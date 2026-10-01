<?php
require_once __DIR__ . '/includes/auth.php';
configureSession();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$customer = getCustomerById($pdo, currentUserId());
$lines = cartLines($pdo);
$errors = [];
$values = [
    'fullname' => $customer['fullname'] ?? '',
    'phone'    => $customer['phone'] ?? '',
    'address'  => $customer['address'] ?? '',
    'mode'     => 'Delivery',
    'payment'  => 'Cash on Pickup',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();

    $values['fullname'] = trim($_POST['fullname'] ?? '');
    $values['address']  = trim($_POST['address'] ?? '');
    $values['mode']     = ($_POST['mode'] ?? 'Delivery') === 'Pickup' ? 'Pickup' : 'Delivery';
    $values['payment']  = in_array($_POST['payment'] ?? '', ['Cash on Pickup', 'GCash', 'Card'], true)
        ? $_POST['payment'] : 'Cash on Pickup';
    // Phone is tied to the logged-in account — not user-editable here.
    $values['phone'] = $customer['phone'] ?? '';

    // The mode/payment radios auto-submit this same form just to refresh the
    // summary (fee, address field). Only the Place Order button should
    // actually validate and create the order.
    if (isset($_POST['place_order'])) {
        if (empty($lines)) {
            $errors[] = 'Your cart is empty — add something from the menu first.';
        }
        if ($values['fullname'] === '') {
            $errors['fullname'] = 'Full name is required.';
        }
        if ($values['mode'] === 'Delivery' && $values['address'] === '') {
            $errors['address'] = 'Delivery address is required.';
        }
    }

    if (isset($_POST['place_order']) && empty($errors)) {
        $subtotal = cartSubtotal($pdo);
        $deliveryFee = $values['mode'] === 'Delivery' ? DELIVERY_FEE : 0.0;
        $total = $subtotal + $deliveryFee;

        try {
            $pdo->beginTransaction();

            // Re-check stock under a row lock (SELECT ... FOR UPDATE) so two
            // customers racing to buy the last item can't both succeed —
            // whichever transaction commits first wins, the other sees the
            // updated stock and is rejected. The trigger in db_shop.sql is
            // the hard backstop; this check just gives a friendlier message
            // and avoids relying on the exception path for the normal case.
            $lockStmt = $pdo->prepare('SELECT productname, stock FROM products WHERE product_id = ? FOR UPDATE');
            foreach ($lines as $l) {
                $lockStmt->execute([$l['product_id']]);
                $row = $lockStmt->fetch();
                if (!$row || (int) $row['stock'] < (int) $l['qty']) {
                    $errors[] = 'Sorry, "' . $l['productname'] . '" only has ' . (int) ($row['stock'] ?? 0) . ' left in stock. Please update your cart.';
                }
            }

            if (!empty($errors)) {
                $pdo->rollBack();
            } else {
                $customerId = currentUserId();
                $stmt = $pdo->prepare('UPDATE customers SET fullname = ?, address = ? WHERE customer_id = ?');
                $stmt->execute([$values['fullname'], $values['address'], $customerId]);

                $orderCode = generateOrderCode($pdo);
                $stmt = $pdo->prepare(
                    'INSERT INTO orders (order_code, customer_id, order_mode, payment_method, subtotal, delivery_fee, total, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                     RETURNING order_id'
                );
                $stmt->execute([
                    $orderCode, $customerId, $values['mode'], $values['payment'],
                    $subtotal, $deliveryFee, $total, 'Pending',
                ]);
                $orderId = (int) $stmt->fetchColumn();

                // Each insert here fires trg_orderdetails_check_stock (blocks
                // if insufficient) then trg_orderdetails_decrement_stock
                // (deducts stock + bumps sold_count) — see sql/db_shop.sql.
                $stmt = $pdo->prepare(
                    'INSERT INTO orderdetails (order_id, product_id, productname_snapshot, price, quantity, meta_snapshot)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                foreach ($lines as $l) {
                    $stmt->execute([
                        $orderId, $l['product_id'], $l['productname'], $l['price'], $l['qty'], $l['default_meta'],
                    ]);
                }

                $pdo->commit();

                cartClear();
                $_SESSION['last_order_code'] = $orderCode;
                header('Location: confirmation.php');
                exit;
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            // SQLSTATE 45000 is raised by the stock trigger (RAISE EXCEPTION) — surface it plainly.
            $errors[] = ($e instanceof PDOException && $e->getCode() === '45000')
                ? 'Sorry, one of your items just sold out. Please update your cart and try again.'
                : 'Something went wrong placing your order. Please try again.';
        }
    }
}

$subtotal = cartSubtotal($pdo);
$deliveryFee = $values['mode'] === 'Delivery' ? DELIVERY_FEE : 0.0;
$total = $subtotal + $deliveryFee;

$pageTitle = 'Checkout — WTF Lounge';
require __DIR__ . '/includes/header.php';
?>

    <div class="page-header">
      <h1 class="display-bubbly" style="font-size:32px;">Almost there!</h1>
      <p class="body-md">Complete your details to enjoy your treats.</p>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="container">
        <div class="form-errors">
          Please fix the following:
          <ul>
            <?php foreach ($errors as $key => $err): ?>
              <li><?= h(is_string($err) ? $err : (string) $err) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    <?php endif; ?>

    <form method="post" action="checkout.php">
      <?= csrfField() ?>
      <div class="checkout-grid">
        <div class="col">

          <div class="tabgroup">
            <label class="tabgroup__tab <?= $values['mode'] === 'Delivery' ? 'is-active' : '' ?>">
              <input type="radio" name="mode" value="Delivery" <?= $values['mode'] === 'Delivery' ? 'checked' : '' ?> style="display:none;" onchange="this.form.submit()">
              Delivery
            </label>
            <label class="tabgroup__tab <?= $values['mode'] === 'Pickup' ? 'is-active' : '' ?>">
              <input type="radio" name="mode" value="Pickup" <?= $values['mode'] === 'Pickup' ? 'checked' : '' ?> style="display:none;" onchange="this.form.submit()">
              Pickup
            </label>
          </div>

          <div class="panel">
            <h2 class="panel__title">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              Your Details
            </h2>
            <div class="field">
              <label for="fullname">Full Name</label>
              <input id="fullname" name="fullname" type="text" placeholder="Jane Doe"
                     value="<?= h($values['fullname']) ?>"
                     class="<?= isset($errors['fullname']) ? 'has-error' : '' ?>">
              <?php if (isset($errors['fullname'])): ?><div class="field-error"><?= h($errors['fullname']) ?></div><?php endif; ?>
            </div>
            <div class="field">
              <label>Phone Number</label>
              <input type="tel" value="<?= h($values['phone']) ?>" disabled style="background:var(--surface-alt);">
            </div>
            <div class="field" id="addressField" style="<?= $values['mode'] === 'Pickup' ? 'display:none;' : '' ?>">
              <label for="address">Delivery Address</label>
              <input id="address" name="address" type="text" placeholder="Street, City, Zip"
                     value="<?= h($values['address']) ?>"
                     class="<?= isset($errors['address']) ? 'has-error' : '' ?>">
              <?php if (isset($errors['address'])): ?><div class="field-error"><?= h($errors['address']) ?></div><?php endif; ?>
            </div>
          </div>

          <div class="panel">
            <h2 class="panel__title">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
              Payment Method
            </h2>
            <div class="payment-options">
              <label class="payment-option <?= $values['payment'] === 'Cash on Pickup' ? 'is-selected' : '' ?>">
                <input type="radio" name="payment" value="Cash on Pickup" <?= $values['payment'] === 'Cash on Pickup' ? 'checked' : '' ?> style="display:none;" onchange="this.form.submit()">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4"/><path d="M3 9v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9"/><path d="M3 9h18"/></svg>
                Cash on Pickup
              </label>
              <label class="payment-option <?= $values['payment'] === 'GCash' ? 'is-selected' : '' ?>">
                <input type="radio" name="payment" value="GCash" <?= $values['payment'] === 'GCash' ? 'checked' : '' ?> style="display:none;" onchange="this.form.submit()">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="12" cy="12" r="3"/></svg>
                GCash
              </label>
              <label class="payment-option <?= $values['payment'] === 'Card' ? 'is-selected' : '' ?>">
                <input type="radio" name="payment" value="Card" <?= $values['payment'] === 'Card' ? 'checked' : '' ?> style="display:none;" onchange="this.form.submit()">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                Card
              </label>
            </div>
          </div>

        </div>

        <div class="col">
          <div class="panel">
            <h2 class="panel__title">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h2l2.6 13.4a2 2 0 0 0 2 1.6h9a2 2 0 0 0 2-1.7L22 7H6"/></svg>
              Order Summary
            </h2>
            <?php if (empty($lines)): ?>
              <p class="body-md" style="color:var(--on-surface-variant);">Your cart is empty.</p>
            <?php else: ?>
              <?php foreach ($lines as $l): ?>
                <div class="summary-item">
                  <img class="summary-item__img" src="<?= h($l['image']) ?>" alt="<?= h($l['productname']) ?>">
                  <div>
                    <p class="summary-item__name"><?= h($l['productname']) ?></p>
                    <p class="summary-item__meta">Qty: <?= (int) $l['qty'] ?><?= $l['default_meta'] ? ' • ' . h($l['default_meta']) : '' ?></p>
                  </div>
                  <span class="summary-item__price"><?= formatPrice($l['line_total']) ?></span>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
            <hr class="summary-divider">
            <div class="summary-row"><span>Subtotal</span><span><?= formatPrice($subtotal) ?></span></div>
            <div class="summary-row"><span><?= $values['mode'] === 'Delivery' ? 'Delivery Fee' : 'Pickup' ?></span><span><?= $values['mode'] === 'Delivery' ? formatPrice($deliveryFee) : 'Free' ?></span></div>
            <hr class="summary-divider">
            <div class="summary-total">
              <span class="summary-total__label">Total</span>
              <span class="summary-total__value"><?= formatPrice($total) ?></span>
            </div>
          </div>
          <button type="submit" name="place_order" value="1" class="btn btn--primary btn--block btn--lg" <?= empty($lines) ? 'disabled style="opacity:.5;"' : '' ?>>
            Place Order
          </button>
        </div>
      </div>
    </form>

<?php require __DIR__ . '/includes/footer.php'; ?>
