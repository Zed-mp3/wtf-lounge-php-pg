<?php
require_once __DIR__ . '/includes/auth.php';
configureSession();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/password_reset.php';
require_once __DIR__ . '/includes/mailer.php';

if (isLoggedIn()) {
    header('Location: menu.php');
    exit;
}

$pageTitle = 'Forgot password — WTF Lounge';
$activeNav = '';
$errors = [];
$mailOn = mailConfigured();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();

    $email = strtolower(trim($_POST['email'] ?? ''));

    if ($email === '') {
        $errors[] = 'Email address is required.';
    } elseif (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    } elseif (loginAttemptsExceeded('forgot')) {
        $errors[] = 'Too many requests. Please wait a few minutes and try again.';
    }

    if (empty($errors)) {
        registerLoginAttempt('forgot');
        try {
            if ($mailOn) {
                // Same screen whether or not the address is registered.
                sendPasswordResetCode($pdo, $email);
                $_SESSION['pw_reset_email'] = $email;
                flashSet('If that email is registered, we just sent you a 6-digit code. Check your spam folder too.');
                header('Location: verify_code.php');
                exit;
            }
            requestPasswordResetByEmail($pdo, $email);
            // Same message whether or not the address is registered.
            flashSet('If that email is registered, the café has been notified and will contact you with a link to reset your password.');
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
    <p class="auth__subtitle"><?php if ($mailOn): ?>Enter the email address on your account. We'll send you a 6-digit code to confirm it's you.<?php else: ?>Enter the email address on your account. We'll let the café know, and they'll contact you with a link to set a new password.<?php endif; ?></p>

    <?php if (!empty($errors)): ?>
      <div class="form-errors">
        <ul><?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <form method="post" action="forgot_password.php" class="auth__form">
      <?= csrfField() ?>
      <div class="auth__field">
        <label for="email">Email Address</label>
        <input type="email" id="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>" placeholder="e.g., juan@gmail.com" autocomplete="email">
      </div>
      <button type="submit" class="btn btn--primary btn--lg"><?= $mailOn ? 'Email me a code' : 'Send request' ?></button>
    </form>
    <p class="auth__footer">
      Remembered it? <a href="login.php">Back to log in</a>
    </p>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
