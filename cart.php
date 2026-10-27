<?php
require_once __DIR__ . '/includes/auth.php';
configureSession();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

// ---- Handle cart mutations (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();

    $action = $_POST['action'] ?? '';
    $productId = (int) ($_POST['product_id'] ?? 0);
    $lineKey = (string) ($_POST['line_key'] ?? '');

    if ($lineKey !== '' && isset(comboData()[$lineKey]) && in_array($action, ['increment', 'decrement', 'remove'], true)) {
        // Customised budget meal line
        $combo = comboData()[$lineKey];
        $mealProduct = getProduct($pdo, (int) $combo['product_id']);
        $maxQty = $mealProduct ? max(1, (int) $mealProduct['stock']) : 1;
        if ($action === 'increment') {
            comboSetQty($lineKey, min((int) $combo['qty'] + 1, $maxQty));
        } elseif ($action === 'decrement') {
            comboSetQty($lineKey, (int) $combo['qty'] - 1);
        } else {
            comboRemove($lineKey);
        }
    } elseif ($action === 'add' && $productId > 0 && getBudgetMeal($pdo, $productId)) {
        // Budget meals must go through the Rice -> Drink -> Add-ons flow.
        header('Location: budget_meal.php?id=' . $productId);
        exit;
    } elseif ($action === 'add' && $productId > 0) {
        $product = getProduct($pdo, $productId);
        if ($product && (int) $product['stock'] > 0) {
            $cart = cartData();
            $desired = ($cart[$productId] ?? 0) + 1;
            cartSetQty($productId, min($desired, (int) $product['stock']));
            flashSet($product['productname'] . ' added to cart.');
        } elseif ($product) {
            flashSet($product['productname'] . ' is out of stock.');
        }
    } elseif ($action === 'increment' && $productId > 0) {
        $product = getProduct($pdo, $productId);
        $cart = cartData();
        $desired = ($cart[$productId] ?? 0) + 1;
        if ($product) {
            $desired = min($desired, (int) $product['stock']);
        }
        cartSetQty($productId, $desired);
    } elseif ($action === 'decrement' && $productId > 0) {
        $cart = cartData();
        cartSetQty($productId, ($cart[$productId] ?? 0) - 1);
    } elseif ($action === 'remove' && $productId > 0) {
        cartRemove($productId);
    } elseif ($action === 'clear') {
        cartClear();
    }

    $redirect = sanitizeLocalRedirect($_POST['redirect'] ?? 'cart.php', 'cart.php');
    header('Location: ' . $redirect);
    exit;
}

$lines = cartLines($pdo);
$subtotal = cartSubtotal($pdo);
$itemCount = array_sum(array_column($lines, 'qty'));

$pageTitle = 'Your Cart — WTF Lounge';
$activeNav = 'cart';
require __DIR__ . '/includes/header.php';
?>

    <div class="page-header">
      <h1 class="headline-lg" style="color:var(--primary);">Your Cart</h1>
    </div>

    <div class="cart-grid">
      <?php if (empty($lines)): ?>
        <div class="empty-state" style="grid-column:1/-1;">
          <div class="tagline-cursive" style="font-size:28px;">Your cart is feeling a little bare...</div>
          <p>Add something sweet to get started.</p>
          <a href="menu.php" class="btn btn--primary">Browse Menu</a>
        </div>
      <?php else: ?>

        <div class="col">
          <?php foreach ($lines as $l): ?>
            <div class="cart-line">
              <img class="cart-line__img" src="<?= h($l['image']) ?>" alt="<?= h($l['productname']) ?>">
              <div class="cart-line__info">
                <h3 class="cart-line__name"><?= h($l['productname']) ?></h3>
                <p class="cart-line__meta"><?= h($l['default_meta']) ?></p>
                <div class="stepper">
                  <form method="post" action="cart.php" class="inline-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="decrement">
                    <?= cartLineIdField($l) ?>
                    <button type="submit" aria-label="Decrease quantity">−</button>
                  </form>
                  <span><?= (int) $l['qty'] ?></span>
                  <form method="post" action="cart.php" class="inline-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="increment">
                    <?= cartLineIdField($l) ?>
                    <button type="submit" aria-label="Increase quantity">+</button>
                  </form>
                </div>
              </div>
              <div class="cart-line__side">
                <span class="cart-line__price"><?= formatPrice($l['line_total']) ?></span>
                <form method="post" action="cart.php" class="inline-form">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="remove">
                  <?= cartLineIdField($l) ?>
                  <button type="submit" class="cart-line__remove" aria-label="Remove <?= h($l['productname']) ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                  </button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
          <a href="menu.php" class="keep-shopping">← Keep Shopping</a>
        </div>

        <div class="panel">
          <h2 class="panel__title" style="color:var(--on-surface);">Order Summary</h2>
          <div class="summary-row"><span>Subtotal (<?= (int) $itemCount ?> items)</span><span><?= formatPrice($subtotal) ?></span></div>
          <div class="summary-row"><span>Delivery fee</span><span>Calculated at checkout</span></div>
          <hr class="summary-divider">
          <div class="summary-total">
            <span class="summary-total__label">Subtotal</span>
            <span class="summary-total__value"><?= formatPrice($subtotal) ?></span>
          </div>
          <a href="checkout.php" class="btn btn--primary btn--block btn--lg" style="margin-top:16px;">
            Checkout Now
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
          </a>
        </div>

      <?php endif; ?>
    </div>

<?php require __DIR__ . '/includes/footer.php'; ?>
