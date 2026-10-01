<?php
require_once __DIR__ . '/includes/auth.php';
configureSession();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

// Reorder: dump a past order's items straight back into the cart.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reorder') {
    csrfVerify();

    $orderCode = $_POST['order_code'] ?? '';
    $order = getOrderByCode($pdo, $orderCode);
    // Only allow reordering your own past order.
    if ($order && (int) $order['customer_id'] === currentUserId()) {
        foreach ($order['items'] as $item) {
            if ($item['product_id']) {
                cartAdd((int) $item['product_id'], (int) $item['quantity']);
            }
        }
        flashSet('Items from order #' . $order['order_code'] . ' added to your cart.');
    }
    header('Location: orders.php');
    exit;
}

$orders = getOrdersForCustomer($pdo, currentUserId());
$itemsByOrder = getOrderItemsFor($pdo, array_column($orders, 'order_id'));

$pageTitle = 'Your Orders — WTF Lounge';
$activeNav = 'orders';
require __DIR__ . '/includes/header.php';
?>

    <div class="profile-header">
      <div class="profile-header__avatar" aria-hidden="true">
        <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </div>
      <div>
        <h1 class="headline-lg" style="color:var(--primary);">Hey, <?= h($_SESSION['fullname']) ?>!</h1>
        <p>Here's everything you've ordered so far.</p>
      </div>
    </div>

    <div class="orders-grid">
      <?php if (empty($orders)): ?>
        <div class="empty-state" style="grid-column:1/-1;">
          <div class="tagline-cursive" style="font-size:28px;">No orders found.</div>
          <p>Place an order and it will show up here.</p>
          <a href="menu.php" class="btn btn--primary">Browse Menu</a>
        </div>
      <?php endif; ?>

      <?php foreach ($orders as $o): ?>
        <?php
          $items = $itemsByOrder[$o['order_id']] ?? [];
          $statusClass = 'status-badge--pending';
          if ($o['status'] === 'Completed') { $statusClass = 'status-badge--completed'; }
          elseif ($o['status'] === 'Cancelled') { $statusClass = 'status-badge--cancelled'; }
        ?>
        <div class="order-card">
          <div class="order-card__head">
            <div>
              <p class="order-card__date"><?= h(date('M j, Y', strtotime($o['order_date']))) ?></p>
              <p class="order-card__id">#<?= h($o['order_code']) ?></p>
            </div>
            <span class="status-badge <?= $statusClass ?>"><?= strtoupper(h($o['status'])) ?></span>
          </div>

          <?php foreach ($items as $item): ?>
            <div class="order-card__item">
              <?php if (!empty($item['image'])): ?>
                <img src="<?= h($item['image']) ?>" alt="<?= h($item['productname_snapshot']) ?>">
              <?php endif; ?>
              <div>
                <p class="order-card__item-name"><?= h($item['productname_snapshot']) ?> &times;<?= (int) $item['quantity'] ?></p>
                <p class="order-card__item-meta"><?= h($item['meta_snapshot']) ?></p>
              </div>
            </div>
          <?php endforeach; ?>

          <div class="order-card__foot">
            <span class="order-card__price"><?= formatPrice($o['total']) ?></span>
            <form method="post" action="orders.php">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="reorder">
              <input type="hidden" name="order_code" value="<?= h($o['order_code']) ?>">
              <!--<button type="submit" class="reorder-btn">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 1 3 6.7"/><path d="M3 21v-6h6"/></svg>
                Reorder
              </button>-->
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

<?php require __DIR__ . '/includes/footer.php'; ?>
