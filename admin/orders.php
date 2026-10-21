<?php
require_once __DIR__ . '/../includes/auth.php';
configureSession();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    csrfVerify();
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if ($orderId > 0 && updateOrderStatus($pdo, $orderId, $status)) {
        error_log(sprintf('AUDIT admin=%d order=%d status=%s ip=%s', currentUserId(), $orderId, $status, clientIp()));
        flashSet('Order updated to "' . $status . '".');
    } else {
        flashSet('Could not update that order.');
    }
    header('Location: orders.php' . (isset($_GET['q']) ? '?q=' . urlencode($_GET['q']) : ''));
    exit;
}

$search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$statusFilter = (string) ($_GET['status'] ?? 'All');
if ($statusFilter !== 'All' && !in_array($statusFilter, ORDER_STATUSES, true)) {
    $statusFilter = 'All';
}
$orders = getAllOrders($pdo, $search, $statusFilter, 150);
$itemsByOrder = getOrderItemsFor($pdo, array_column($orders, 'order_id'));

$pageTitle = 'Manage Orders — WTF Lounge Admin';
$activeAdminNav = 'orders';
require __DIR__ . '/includes/admin_header.php';
?>

<h1 class="headline-lg">Manage Orders</h1>

<form method="get" action="orders.php" class="admin-toolbar">
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search order #, name, or phone">
    <select name="status">
        <option value="All" <?= $statusFilter === 'All' ? 'selected' : '' ?>>All statuses</option>
        <?php foreach (ORDER_STATUSES as $s): ?>
            <option value="<?= h($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= h($s) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn--secondary">Filter</button>
</form>

<div class="table-scroll">
    <table class="admin-table">
        <thead>
            <tr>
                <th>Order</th>
                <th>Customer</th>
                <th>Items</th>
                <th>Mode / Payment</th>
                <th>Total</th>
                <th>Status</th>
                <th>Placed</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders as $o): ?>
                <?php $items = $itemsByOrder[$o['order_id']] ?? []; ?>
                <tr>
                    <td><strong>#<?= h($o['order_code']) ?></strong></td>
                    <td>
                        <?= h($o['fullname'] ?? '—') ?>
                        <br><small><?= h($o['phone'] ?? '') ?></small>
                    </td>
                    <td>
                        <?php foreach ($items as $it): ?>
                            <div style="font-size:13px;">
                                <?= (int) $it['quantity'] ?>&times; <?= h($it['productname_snapshot']) ?>
                            </div>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <?= h($o['order_mode']) ?>
                        <br><small><?= h($o['payment_method']) ?></small>
                    </td>
                    <td><strong><?= formatPrice($o['total']) ?></strong></td>
                    <td>
                        <form method="post" action="orders.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="order_id" value="<?= (int) $o['order_id'] ?>">
                            <select name="status" data-autosubmit>
                                <?php foreach (ORDER_STATUSES as $s): ?>
                                    <option value="<?= h($s) ?>" <?= $o['status'] === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td><?= h(date('M j, g:ia', strtotime($o['order_date']))) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($orders)): ?>
                <tr><td colspan="7" style="text-align:center;padding:40px;">No matching orders.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>