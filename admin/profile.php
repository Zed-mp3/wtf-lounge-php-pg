<?php
require_once __DIR__ . '/../includes/auth.php';
configureSession();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/profile.php';

requireAdmin();

$errors = handleProfilePost($pdo, 'profile.php');
$customer = getCustomerById($pdo, (int) currentUserId());
if (!$customer) {
    header('Location: ../logout.php');
    exit;
}

$pageTitle = 'My Profile — WTF Lounge Admin';
$activeAdminNav = 'profile';
require __DIR__ . '/includes/admin_header.php';
?>

<h1 class="headline-lg">My Profile</h1>
<p class="body-md">Update your admin account details and password.</p>

<div style="max-width:640px;">
  <?php renderProfileCards($customer, $errors, 'profile.php'); ?>
</div>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
