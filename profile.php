<?php
require_once __DIR__ . '/includes/auth.php';
configureSession();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/profile.php';

requireLogin();

$errors = handleProfilePost($pdo, 'profile.php');
$customer = getCustomerById($pdo, (int) currentUserId());
if (!$customer) {
    header('Location: logout.php');
    exit;
}

$pageTitle = 'My Profile — WTF Lounge';
$activeNav = 'profile';
require __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding-top:24px; padding-bottom:40px; max-width:640px;">
  <h1 class="headline-lg">My Profile</h1>
  <p class="body-md">Update your details and password.</p>
  <?php renderProfileCards($customer, $errors, 'profile.php'); ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
