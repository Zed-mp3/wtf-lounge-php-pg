<?php
require_once __DIR__ . '/../includes/auth.php';
configureSession();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/password_reset.php';

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
    } elseif ($action === 'make_reset_link' && $customerId > 0) {
        $stmt = $pdo->prepare('SELECT fullname, phone FROM customers WHERE customer_id = ?');
        $stmt->execute([$customerId]);
        $target = $stmt->fetch();
        if ($target) {
            try {
                $token = issuePasswordResetLink($pdo, $customerId);
                // Shown once on the next page load; only a hash of the token is stored.
                $_SESSION['new_reset_link'] = [
                    'name'  => $target['fullname'],
                    'phone' => $target['phone'],
                    'url'   => passwordResetUrl($token),
                ];
            } catch (PDOException $e) {
                error_log('Could not make reset link: ' . $e->getMessage());
                flashSet('Could not make a reset link. Did you run sql/password_resets.sql?');
            }
        }
    } elseif ($action === 'dismiss_reset') {
        try {
            dismissPasswordReset($pdo, (int) ($_POST['reset_id'] ?? 0));
            flashSet('Request dismissed.');
        } catch (PDOException $e) {
            error_log('Could not dismiss reset: ' . $e->getMessage());
        }
    }

    header('Location: users.php' . (isset($_GET['q']) ? '?q=' . urlencode($_GET['q']) : ''));
    exit;
}

$search = trim($_GET['q'] ?? '');
$customers = getAllCustomers($pdo, $search);

$newLink = $_SESSION['new_reset_link'] ?? null;
unset($_SESSION['new_reset_link']);

$openResets = [];
$resetSetupNeeded = false;
try {
    $openResets = getOpenPasswordResets($pdo);
} catch (PDOException $e) {
    $resetSetupNeeded = true; // password_resets table not created yet
}

$pageTitle = 'Manage Users — WTF Lounge Admin';
$activeAdminNav = 'users';
require __DIR__ . '/includes/admin_header.php';
?>

<h1 class="headline-lg">Manage Users</h1>
<p class="body-md">View and manage customer accounts. You can update passwords for any user, or make a one-time reset link for a customer who forgot theirs.</p>

<?php if ($resetSetupNeeded): ?>
    <div class="admin-welcome" style="flex-direction:column; align-items:stretch;">
        <p style="margin:0;">Forgot-password needs a one-time setup: run <code>sql/password_resets.sql</code> in the Supabase SQL editor, then reload this page.</p>
    </div>
<?php endif; ?>

<?php if ($newLink): ?>
    <div class="admin-welcome" style="flex-direction:column; align-items:stretch;">
        <h2 style="font-family:'Plus Jakarta Sans',sans-serif; font-weight:700; font-size:18px; margin:0 0 8px;">Reset link for <?= h($newLink['name']) ?> (<?= h($newLink['phone']) ?>)</h2>
        <p style="margin:0 0 12px;">Make sure it is really the customer (for example, call them back), then send them this link yourself by text or Messenger. It works once and expires in <?= (int) RESET_LINK_HOURS ?> hours. It will not be shown again.</p>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <input type="text" id="resetLink" readonly value="<?= h($newLink['url']) ?>" style="flex:1; min-width:240px; padding:8px 12px; border:1px solid var(--outline-variant); border-radius:var(--radius-sm);">
            <button type="button" id="copyResetLink" class="btn btn--secondary">Copy</button>
        </div>
    </div>
    <script>
    document.getElementById('copyResetLink').addEventListener('click', function () {
        var input = document.getElementById('resetLink');
        input.select();
        if (navigator.clipboard) { navigator.clipboard.writeText(input.value); } else { document.execCommand('copy'); }
        this.textContent = 'Copied';
    });
    </script>
<?php endif; ?>

<?php if (!empty($openResets)): ?>
    <h2 class="panel__title" style="margin-top:24px; margin-bottom:8px;">Password reset requests</h2>
    <div class="table-scroll">
        <table class="admin-table">
            <thead>
                <tr><th>Name</th><th>Phone</th><th>Requested</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($openResets as $r): ?>
                    <?php
                    $waiting = $r['status'] === 'requested';
                    $expired = !$waiting && strtotime((string) $r['expires_at']) <= time();
                    ?>
                    <tr>
                        <td><strong><?= h($r['fullname']) ?></strong></td>
                        <td><?= h($r['phone']) ?></td>
                        <td><?= h(date('M j, g:i A', strtotime($r['requested_at']))) ?></td>
                        <td>
                            <?php if ($waiting): ?>
                                <span class="status-badge status-badge--pending">Waiting for you</span>
                            <?php elseif ($expired): ?>
                                <span class="status-badge status-badge--cancelled">Link expired</span>
                            <?php else: ?>
                                <span class="status-badge status-badge--completed">Link made · expires <?= h(date('M j, g:i A', strtotime($r['expires_at']))) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="admin-actions">
                                <form method="post" action="users.php">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="make_reset_link">
                                    <input type="hidden" name="customer_id" value="<?= (int) $r['customer_id'] ?>">
                                    <button type="submit" class="btn btn--secondary" style="padding:4px 12px; font-size:11px;"><?= $waiting ? 'Make link' : 'New link' ?></button>
                                </form>
                                <form method="post" action="users.php">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="dismiss_reset">
                                    <input type="hidden" name="reset_id" value="<?= (int) $r['reset_id'] ?>">
                                    <button type="submit" class="btn btn--outline" style="padding:4px 12px; font-size:11px;">Dismiss</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

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
                        <form method="post" action="users.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" style="margin-top:6px;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="make_reset_link">
                            <input type="hidden" name="customer_id" value="<?= (int) $c['customer_id'] ?>">
                            <button type="submit" class="btn btn--outline" style="padding:4px 12px; font-size:11px;">Make reset link</button>
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
