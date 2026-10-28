<?php
require_once __DIR__ . '/../includes/auth.php';
configureSession();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();

$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';
    $customerId = (int) ($_POST['customer_id'] ?? 0);

    // Only allow password updates - removed admin toggle
    if ($action === 'set_password' && $customerId > 0) {
        $newPassword = $_POST['new_password'] ?? '';
        if (strlen($newPassword) < 8) {
            flashSet('Password must be at least 8 characters.');
        } else {
            setCustomerPassword($pdo, $customerId, $newPassword);
            flashSet('Password updated for that account.');
        }
    }

    header('Location: users.php' . (isset($_GET['q']) ? '?q=' . urlencode($_GET['q']) : ''));
    exit;
}

$search = trim($_GET['q'] ?? '');
$customers = getAllCustomers($pdo, $search);

$pageTitle = 'Manage Users — WTF Lounge Admin';
$activeAdminNav = 'users';
require __DIR__ . '/includes/admin_header.php';
?>

<h1 class="headline-lg">Manage Users</h1>
<p class="body-md">View and manage customer accounts. You can update passwords for any user.</p>

<form method="get" action="users.php" class="admin-toolbar">
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search by name or phone">
    <button type="submit" class="btn btn--secondary">Search</button>
</form>

<div class="table-scroll">
    <table class="admin-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Phone</th>
                <th>Address</th>
                <th>Role</th>
                <th>Joined</th>
                <th>Set Password</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($customers as $c): ?>
                <tr>
                    <td><strong><?= h($c['fullname']) ?></strong></td>
                    <td><?= h($c['phone']) ?></td>
                    <td><?= h($c['address'] ?: '—') ?></td>
                    <td>
                        <?= $c['is_admin'] ? '<span class="badge-admin">ADMIN</span>' : 'Customer' ?>
                    </td>
                    <td><?= h(date('M j, Y', strtotime($c['created_at']))) ?></td>
                    <td>
                        <form method="post" action="users.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" class="admin-password-form">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="set_password">
                            <input type="hidden" name="customer_id" value="<?= (int) $c['customer_id'] ?>">
                            <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                                <input type="password" name="new_password" placeholder="New password" minlength="8" 
                                       style="padding:6px 12px; border:1px solid var(--outline-variant); border-radius:var(--radius-sm); font-size:13px; min-width:120px;">
                                <button type="submit" style="padding:6px 16px; border:none; border-radius:var(--radius-sm); background:var(--primary); color:var(--on-primary); font-weight:600; font-size:12px; cursor:pointer; transition:background 0.15s ease;">
                                    Set Password
                                </button>
                            </div>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($customers)): ?>
                <tr><td colspan="6" style="text-align:center;padding:40px;">No matching customers found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>