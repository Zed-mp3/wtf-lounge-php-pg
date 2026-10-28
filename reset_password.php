<?php
require_once __DIR__ . '/includes/auth.php';
configureSession();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/password_reset.php';

// The token is in the URL: keep it out of caches and Referer headers.
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$pageTitle = 'Reset password — WTF Lounge';
$activeNav = '';
$errors = [];

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$reset = findPasswordResetByToken($pdo, $token);

if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();

    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        if (completePasswordReset($pdo, $token, $password)) {
            flashSet('Your password has been updated. Please log in with your new password.');
            header('Location: login.php');
            exit;
        }
        $reset = null; // used or expired a moment ago
    }
}
?>

<?php require __DIR__ . '/includes/header.php'; ?>

<section class="auth">
  <div class="auth__box">
    <?php if (!$reset): ?>
      <h2 class="auth__title">Link not valid</h2>
      <p class="auth__subtitle">This reset link has expired or was already used. You can ask for a new one.</p>
      <p class="auth__footer"><a href="forgot_password.php">Request a new link</a> · <a href="login.php">Log in</a></p>
    <?php else: ?>
      <h2 class="auth__title">Set a new password</h2>
      <p class="auth__subtitle">Hi <?= h($reset['fullname']) ?>, choose a new password for your account.</p>

      <?php if (!empty($errors)): ?>
        <div class="form-errors">
          <ul><?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>

      <form method="post" action="reset_password.php" class="auth__form">
        <?= csrfField() ?>
        <input type="hidden" name="token" value="<?= h($token) ?>">
        <div class="auth__field">
          <label for="password">New password</label>
          <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
        </div>
        <div class="auth__field">
          <label for="confirm_password">Confirm new password</label>
          <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn--primary btn--lg">Update password</button>
      </form>
    <?php endif; ?>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
