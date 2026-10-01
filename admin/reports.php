<?php
require_once __DIR__ . '/../includes/auth.php';
configureSession();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();

// Get date filters
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

// Sales data
$salesData = [];
$salesByDay = [];
$topProducts = [];
$salesByCategory = [];

try {
    // Total revenue
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total), 0) as total_revenue,
               COUNT(*) as total_orders,
               COALESCE(AVG(total), 0) as avg_order_value
        FROM orders 
        WHERE status = 'Completed' 
        AND DATE(order_date) BETWEEN ? AND ?
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $salesData = $stmt->fetch();

    // Daily sales for chart
    $stmt = $pdo->prepare("
        SELECT DATE(order_date) as day,
               COUNT(*) as orders,
               COALESCE(SUM(total), 0) as revenue
        FROM orders 
        WHERE status = 'Completed' 
        AND DATE(order_date) BETWEEN ? AND ?
        GROUP BY DATE(order_date)
        ORDER BY day ASC
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $salesByDay = $stmt->fetchAll();

    // Top selling products
    $stmt = $pdo->prepare("
        SELECT p.productname,
               COALESCE(SUM(od.quantity), 0) as total_sold,
               COALESCE(SUM(od.quantity * od.price), 0) as total_revenue
        FROM orderdetails od
        JOIN orders o ON od.order_id = o.order_id
        JOIN products p ON od.product_id = p.product_id
        WHERE o.status = 'Completed'
        AND DATE(o.order_date) BETWEEN ? AND ?
        GROUP BY p.product_id, p.productname
        ORDER BY total_sold DESC
        LIMIT 10
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $topProducts = $stmt->fetchAll();

    // Sales by category
    $stmt = $pdo->prepare("
        SELECT c.categoryname,
               COALESCE(SUM(od.quantity), 0) as total_sold,
               COALESCE(SUM(od.quantity * od.price), 0) as total_revenue
        FROM orderdetails od
        JOIN orders o ON od.order_id = o.order_id
        JOIN products p ON od.product_id = p.product_id
        LEFT JOIN categories c ON p.category_id = c.category_id
        WHERE o.status = 'Completed'
        AND DATE(o.order_date) BETWEEN ? AND ?
        GROUP BY c.category_id, c.categoryname
        ORDER BY total_revenue DESC
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $salesByCategory = $stmt->fetchAll();

    // Order status distribution
    $stmt = $pdo->prepare("
        SELECT status,
               COUNT(*) as count
        FROM orders 
        WHERE DATE(order_date) BETWEEN ? AND ?
        GROUP BY status
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $orderStatuses = $stmt->fetchAll();

    // Delivery vs Pickup
    $stmt = $pdo->prepare("
        SELECT order_mode,
               COUNT(*) as count,
               COALESCE(SUM(total), 0) as revenue
        FROM orders 
        WHERE status = 'Completed'
        AND DATE(order_date) BETWEEN ? AND ?
        GROUP BY order_mode
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $orderModes = $stmt->fetchAll();

} catch (Exception $e) {
    // Handle errors gracefully
    flashSet('Error loading reports: ' . $e->getMessage());
}

$pageTitle = 'Reports — WTF Lounge Admin';
$activeAdminNav = 'reports';
require __DIR__ . '/includes/admin_header.php';
?>

<h1 class="headline-lg">Reports & Analytics</h1>
<p class="body-md">Sales data and insights for your business.</p>

<!-- Date Filter -->
<form method="get" action="reports.php" class="admin-toolbar">
    <label style="font-weight:600; font-size:13px;">From:</label>
    <input type="date" name="date_from" value="<?= h($dateFrom) ?>" style="max-width:180px;">
    <label style="font-weight:600; font-size:13px;">To:</label>
    <input type="date" name="date_to" value="<?= h($dateTo) ?>" style="max-width:180px;">
    <button type="submit" class="btn btn--secondary">Update Report</button>
</form>

<!-- Stats Cards -->
<div class="admin-stats">
    <div class="admin-stat">
        <div class="admin-stat__value"><?= formatPrice($salesData['total_revenue'] ?? 0) ?></div>
        <div class="admin-stat__label">Total Revenue</div>
    </div>
    <div class="admin-stat">
        <div class="admin-stat__value"><?= (int) ($salesData['total_orders'] ?? 0) ?></div>
        <div class="admin-stat__label">Total Orders</div>
    </div>
    <div class="admin-stat">
        <div class="admin-stat__value"><?= formatPrice($salesData['avg_order_value'] ?? 0) ?></div>
        <div class="admin-stat__label">Average Order Value</div>
    </div>
    <div class="admin-stat">
        <div class="admin-stat__value"><?= count($topProducts) ?></div>
        <div class="admin-stat__label">Top Products Sold</div>
    </div>
</div>

<!-- Two Column Layout -->
<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:24px;">
    <!-- Sales by Category -->
    <div class="panel">
        <h2 class="panel__title">Sales by Category</h2>
        <?php if (empty($salesByCategory)): ?>
            <p style="color:var(--on-surface-variant);">No data available.</p>
        <?php else: ?>
            <?php $maxRevenue = max(array_column($salesByCategory, 'total_revenue') ?: [1]); ?>
            <?php foreach ($salesByCategory as $cat): ?>
                <div style="margin-bottom:12px;">
                    <div style="display:flex; justify-content:space-between; font-size:13px; margin-bottom:4px;">
                        <span><?= h($cat['categoryname'] ?? 'Uncategorized') ?></span>
                        <span><strong><?= formatPrice($cat['total_revenue']) ?></strong></span>
                    </div>
                    <div style="background:var(--surface-container-high); border-radius:var(--radius-full); overflow:hidden; height:8px;">
                        <div style="background:var(--primary); height:100%; width:<?= ($maxRevenue > 0) ? round(($cat['total_revenue'] / $maxRevenue) * 100) : 0 ?>%; transition:width 0.3s ease;"></div>
                    </div>
                    <div style="font-size:11px; color:var(--on-surface-variant); margin-top:2px;">
                        <?= (int) $cat['total_sold'] ?> items sold
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Order Mode -->
    <div class="panel">
        <h2 class="panel__title">Delivery vs Pickup</h2>
        <?php if (empty($orderModes)): ?>
            <p style="color:var(--on-surface-variant);">No data available.</p>
        <?php else: ?>
            <?php $totalRevenue = array_sum(array_column($orderModes, 'revenue') ?: [0]); ?>
            <?php foreach ($orderModes as $mode): ?>
                <div style="margin-bottom:12px;">
                    <div style="display:flex; justify-content:space-between; font-size:13px; margin-bottom:4px;">
                        <span><?= h($mode['order_mode']) ?></span>
                        <span><strong><?= formatPrice($mode['revenue']) ?></strong></span>
                    </div>
                    <div style="background:var(--surface-container-high); border-radius:var(--radius-full); overflow:hidden; height:8px;">
                        <div style="background:var(--secondary); height:100%; width:<?= ($totalRevenue > 0) ? round(($mode['revenue'] / $totalRevenue) * 100) : 0 ?>%; transition:width 0.3s ease;"></div>
                    </div>
                    <div style="font-size:11px; color:var(--on-surface-variant); margin-top:2px;">
                        <?= (int) $mode['count'] ?> orders
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Top Selling Products -->
<h2 class="panel__title" style="margin-bottom:16px;">Top Selling Products</h2>
<div class="table-scroll">
    <table class="admin-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Product</th>
                <th>Units Sold</th>
                <th>Total Revenue</th>
                <th>Avg. Price</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($topProducts)): ?>
                <tr><td colspan="5" style="text-align:center;padding:40px;">No sales data available.</td></tr>
            <?php else: ?>
                <?php foreach ($topProducts as $index => $product): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><strong><?= h($product['productname']) ?></strong></td>
                        <td><?= (int) $product['total_sold'] ?></td>
                        <td><strong><?= formatPrice($product['total_revenue']) ?></strong></td>
                        <td><?= formatPrice($product['total_revenue'] / max(1, $product['total_sold'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Daily Sales Trend -->
<h2 class="panel__title" style="margin-top:24px; margin-bottom:16px;">Daily Sales Trend</h2>
<div class="panel" style="padding:24px;">
    <?php if (empty($salesByDay)): ?>
        <p style="color:var(--on-surface-variant); text-align:center;">No data available for this period.</p>
    <?php else: ?>
        <div style="display:flex; gap:4px; align-items:flex-end; min-height:200px; padding:8px 0;">
            <?php 
            $maxRevenue = max(array_column($salesByDay, 'revenue') ?: [1]);
            $maxOrders = max(array_column($salesByDay, 'orders') ?: [1]);
            ?>
            <?php foreach ($salesByDay as $day): ?>
                <div style="flex:1; display:flex; flex-direction:column; align-items:center; gap:4px;">
                    <div style="font-size:10px; color:var(--on-surface-variant);"><?= formatPrice($day['revenue']) ?></div>
                    <div style="width:100%; background:var(--surface-container-high); border-radius:var(--radius-sm) var(--radius-sm) 0 0; height:120px; position:relative; overflow:hidden;">
                        <div style="position:absolute; bottom:0; left:0; right:0; background:var(--primary); border-radius:var(--radius-sm) var(--radius-sm) 0 0; height:<?= ($maxRevenue > 0) ? round(($day['revenue'] / $maxRevenue) * 100) : 0 ?>%; transition:height 0.3s ease;"></div>
                    </div>
                    <div style="font-size:10px; color:var(--on-surface-variant); text-align:center;">
                        <?= date('M d', strtotime($day['day'])) ?>
                    </div>
                    <div style="font-size:9px; color:var(--outline);">
                        <?= (int) $day['orders'] ?> orders
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Order Status Distribution -->
<h2 class="panel__title" style="margin-top:24px; margin-bottom:16px;">Order Status Distribution</h2>
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:12px;">
    <?php if (empty($orderStatuses)): ?>
        <p style="color:var(--on-surface-variant);">No data available.</p>
    <?php else: ?>
        <?php foreach ($orderStatuses as $status): ?>
            <div class="admin-stat" style="padding:16px;">
                <div class="admin-stat__value" style="font-size:24px;"><?= (int) $status['count'] ?></div>
                <div class="admin-stat__label"><?= h($status['status']) ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>