<?php
require_once __DIR__ . '/includes/auth.php';
configureSession();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/password_reset.php';

if (isLoggedIn()) {
    header('Location: menu.php');
    exit;
}

$pageTitle = 'Forgot password — WTF Lounge';
$activeNav = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();

    $phone = trim($_POST['phone'] ?? '');

    if ($phone === '') {
        $errors[] = 'Phone number is required.';
    } elseif (!preg_match('/^[\d\-\+\s]{7,20}$/', $phone)) {
        $errors[] = 'Enter a valid phone number.';
    } elseif (loginAttemptsExceeded('forgot')) {
        $errors[] = 'Too many requests. Please wait a few minutes and try again.';
    }

    if (empty($errors)) {
        registerLoginAttempt('forgot');
        try {
            requestPasswordReset($pdo, $phone);
            // Same message whether or not the number is registered.
            flashSet('If that phone number is registered, the café has been notified and will contact you with a link to reset your password.');
            header('Location: login.php');
            exit;
        } catch (PDOException $e) {
            error_log('Password reset request failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong. Please try again in a moment.';
        }
    }
}
?>

<?php require __DIR__ . '/includes/header.php'; ?>

<section class="auth">
  <div class="auth__box">
    <h2 class="auth__title">Forgot your password?</h2>
    <p class="auth__subtitle">Enter the phone number you registered with. We'll let the café know, and they'll contact you with a link to set a new password.</p>

    <?php if (!empty($errors)): ?>
      <div class="form-errors">
        <ul><?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <form method="post" action="forgot_password.php" class="auth__form">
      <?= csrfField() ?>
      <div class="auth__field">
        <label for="phone">Phone Number</label>
        <input type="tel" id="phone" name="phone" required value="<?= h($_POST['phone'] ?? '') ?>" placeholder="e.g., 0917-123-4567">
      </div>
      <button type="submit" class="btn btn--primary btn--lg">Send request</button>
    </form>
    <p class="auth__footer">
      Remembered it? <a href="login.php">Back to log in</a>
    </p>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
