<?php
require_once __DIR__ . '/includes/auth.php';
configureSession();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$orderCode = $_SESSION['last_order_code'] ?? ($_GET['order'] ?? null);
$order = $orderCode ? getOrderByCode($pdo, $orderCode) : null;

// Ownership check: a customer may only view their own order (unless admin).
// Without this, anyone could view any receipt by guessing/sharing an order code.
if ($order && !isAdmin() && (int) $order['customer_id'] !== currentUserId()) {
    $order = null;
}

$pageTitle = 'Order Confirmed — WTF Lounge';
require __DIR__ . '/includes/header.php';
?>

    <div class="confirm">
      <?php if (!$order): ?>
        <h1 class="display-bubbly" style="font-size:32px;">No order found</h1>
        <p>We couldn't find that order. <a href="menu.php" style="color:var(--primary); text-decoration:underline;">Browse the menu</a> to place one.</p>
      <?php else: ?>
        <div class="confirm__blob">
          <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>
        </div>
        <h1 class="display-bubbly" style="font-size:32px;">Yay! Your order is blooming!</h1>
        <p>We're preparing your treats with love and care.</p>

        <div class="receipt">
          <div class="receipt__head">
            <div>
              <p class="receipt__label">ORDER NUMBER</p>
              <p class="receipt__value">#<?= h($order['order_code']) ?></p>
            </div>
            <div class="pill-badge">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
              <?= $order['order_mode'] === 'Delivery' ? '20-30 mins' : '15-20 mins' ?>
            </div>
          </div>
          <h2 class="panel__title" style="color:var(--on-surface);">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18"/></svg>
            Order Summary
          </h2>
          <?php foreach ($order['items'] as $idx => $item): ?>
            <div class="summary-item">
              <span style="width:24px;height:24px;border-radius:50%;background:var(--primary-fixed);color:var(--on-primary-fixed-variant);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;"><?= $idx + 1 ?></span>
              <p class="summary-item__name" style="margin:0;"><?= h($item['productname_snapshot']) ?> &times;<?= (int) $item['quantity'] ?></p>
              <span class="summary-item__price"><?= formatPrice($item['price'] * $item['quantity']) ?></span>
            </div>
          <?php endforeach; ?>
          <hr class="summary-divider">
          <div class="summary-row"><span>Subtotal</span><span><?= formatPrice($order['subtotal']) ?></span></div>
          <div class="summary-row"><span><?= $order['order_mode'] === 'Delivery' ? 'Delivery Fee' : 'Pickup' ?></span><span><?= formatPrice($order['delivery_fee']) ?></span></div>
          <hr class="summary-divider">
          <div class="summary-total">
            <span class="summary-total__label">Total Paid</span>
            <span class="summary-total__value"><?= formatPrice($order['total']) ?></span>
          </div>
        </div>

        <div class="confirm-actions">
          <a href="orders.php" class="btn btn--primary btn--block btn--lg">Track Order</a>
          <a href="menu.php" class="btn btn--secondary btn--block">Back to Menu</a>
        </div>
      <?php endif; ?>
    </div>

<?php require __DIR__ . '/includes/footer.php'; ?>
