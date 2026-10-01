<?php
require_once __DIR__ . '/../includes/auth.php';
configureSession();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();

$totalOrders = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$pendingOrders = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('Pending','Preparing','Out for Delivery','Ready for Pickup')")->fetchColumn();
$totalCustomers = (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
$revenue = (float) $pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status = 'Completed'")->fetchColumn();
$lowStockCount = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE is_active AND stock <= ' . LOW_STOCK_THRESHOLD)->fetchColumn();

$recentOrders = getAllOrders($pdo, null, null, 8);

$pageTitle = 'Admin Dashboard — WTF Lounge';
$activeAdminNav = 'dashboard';
require __DIR__ . '/includes/admin_header.php';
?>

<h1 class="headline-lg">Admin Dashboard</h1>
<p class="body-md">A quick look at what's happening at WTF Lounge.</p>

<section class="admin-welcome">
    <div class="admin-welcome__text">
        <h2>Welcome back, <?= h($_SESSION['fullname'] ?? 'Admin') ?></h2>
        <p>Here's what you can do today:</p>
    </div>
    <div class="admin-welcome__actions">
        <a href="orders.php" class="btn btn--primary">Manage Orders</a>
        <a href="users.php" class="btn btn--secondary">Manage Users</a>
        <a href="menu.php" class="btn btn--outline">Manage Menu</a>
        <a href="reports.php" class="btn btn--outline">View Reports</a>
    </div>
</section>

<div class="admin-stats">
    <div class="admin-stat">
        <div class="admin-stat__value"><?= $totalOrders ?></div>
        <div class="admin-stat__label">Total Orders</div>
    </div>
    <div class="admin-stat">
        <div class="admin-stat__value"><?= $pendingOrders ?></div>
        <div class="admin-stat__label">Active / In Progress</div>
    </div>
    <div class="admin-stat">
        <div class="admin-stat__value"><?= $totalCustomers ?></div>
        <div class="admin-stat__label">Registered Customers</div>
    </div>
    <div class="admin-stat">
        <div class="admin-stat__value"><?= formatPrice($revenue) ?></div>
        <div class="admin-stat__label">Revenue (Completed)</div>
    </div>
    <div class="admin-stat">
        <div class="admin-stat__value"><?= $lowStockCount ?></div>
        <div class="admin-stat__label">Low / Out of Stock Items</div>
    </div>
</div>

<h2 class="panel__title" style="margin-bottom: 16px;">Recent Orders</h2>
<div class="table-scroll">
    <table class="admin-table">
        <thead>
            <tr>
                <th>Order</th>
                <th>Customer</th>
                <th>Mode</th>
                <th>Total</th>
                <th>Status</th>
                <th>Placed</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentOrders as $o): ?>
                <tr>
                    <td><strong>#<?= h($o['order_code']) ?></strong></td>
                    <td>
                        <?= h($o['fullname'] ?? '—') ?>
                        <br><small><?= h($o['phone'] ?? '') ?></small>
                    </td>
                    <td><?= h($o['order_mode']) ?></td>
                    <td><strong><?= formatPrice($o['total']) ?></strong></td>
                    <td>
                        <span class="status-badge <?= $o['status'] === 'Completed' ? 'status-badge--completed' : 'status-badge--active' ?>">
                            <?= h($o['status']) ?>
                        </span>
                    </td>
                    <td><?= h(date('M j, Y g:ia', strtotime($o['order_date']))) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($recentOrders)): ?>
                <tr><td colspan="6" style="text-align:center;padding:40px;">No orders yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<p style="margin-top:16px;">
    <a href="orders.php" class="btn btn--secondary">Manage all orders →</a>
</p>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>