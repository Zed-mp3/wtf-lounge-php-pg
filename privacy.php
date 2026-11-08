<?php
require_once __DIR__ . '/includes/auth.php';
configureSession();
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Privacy Policy — WTF Lounge';
$activeNav = '';
?>

<?php require __DIR__ . '/includes/header.php'; ?>

<section class="auth">
  <div class="auth__box" style="max-width:720px;">
    <h2 class="auth__title">Privacy Policy</h2>
    <p class="auth__subtitle">Last updated: November 2026</p>

    <h3>What we collect</h3>
    <p>When you create an account or place an order, WTF Lounge stores your full name, phone number, email address, delivery address, your orders, and a scrambled (hashed) version of your password. We never store your real password.</p>

    <h3>How we use it</h3>
    <p>We use this information only to run your account and deliver your orders. We use your email address only to send you a verification code when you sign up and a code when you reset your password.</p>

    <h3>Email sending</h3>
    <p>Emails are sent from our own Gmail account using the Gmail API. The app has permission to send email only. It cannot read, delete, or change any email, and it does not access your Google account.</p>

    <h3>Sharing</h3>
    <p>We do not sell or share your personal information with anyone, except as needed to run the service (our hosting and database providers).</p>

    <h3>Your choices</h3>
    <p>You can ask the café staff to correct or delete your account and the information we hold about you.</p>

    <p class="auth__footer"><a href="index.php">Back to home</a></p>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
