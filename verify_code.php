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

$email = (string) ($_SESSION['pw_reset_email'] ?? '');
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: forgot_password.php');
    exit;
}

header('Cache-Control: no-store');

$pageTitle = 'Enter code — WTF Lounge';
$activeNav = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();

    $code = preg_replace('/\D+/', '', (string) ($_POST['code'] ?? ''));

    if (loginAttemptsExceeded('verify')) {
        $errors[] = 'Too many tries. Please wait a few minutes and try again.';
    } else {
        registerLoginAttempt('verify');
        try {
            $token = verifyPasswordResetCode($pdo, $email, $code);
            if ($token !== null) {
                unset($_SESSION['pw_reset_email']);
                clearLoginAttempts('verify');
                header('Location: reset_password.php?token=' . $token);
                exit;
            }
            $errors[] = 'That code is wrong or has expired.';
        } catch (PDOException $e) {
            error_log('Password reset code check failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong. Please try again in a moment.';
        }
    }
}
?>

<?php require __DIR__ . '/includes/header.php'; ?>

<section class="auth">
  <div class="auth__box">
    <h2 class="auth__title">Enter your code</h2>
    <p class="auth__subtitle">We emailed a 6-digit code to <?= h(maskEmail($email)) ?>. It works for <?= (int) RESET_CODE_MINUTES ?> minutes. Check your spam folder if you don't see it.</p>

    <?php if (!empty($errors)): ?>
      <div class="form-errors">
        <ul><?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <form method="post" action="verify_code.php" class="auth__form">
      <?= csrfField() ?>
      <div class="auth__field">
        <label for="code">6-digit code</label>
        <input type="text" id="code" name="code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" autofocus placeholder="123456">
      </div>
      <button type="submit" class="btn btn--primary btn--lg">Verify</button>
    </form>
    <p class="auth__footer">
      No email yet? <a href="forgot_password.php">Send a new code</a> (you can ask again after a minute).
      The café can also send you a reset link if emails aren't arriving.
    </p>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
