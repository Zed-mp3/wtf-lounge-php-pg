<?php
require_once __DIR__ . '/includes/auth.php';
configureSession();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

// Already signed in — no need to see the login form again.
if (isLoggedIn()) {
    header('Location: menu.php');
    exit;
}

$pageTitle = 'Login — WTF Lounge';
$activeNav = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();

    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($phone === '') {
        $errors[] = 'Phone number is required.';
    }
    if ($password === '') {
        $errors[] = 'Password is required.';
    }

    if (empty($errors)) {
        if (loginAttemptsExceeded($phone)) {
            $errors[] = 'Too many login attempts. Please wait a few minutes and try again.';
        } else {
            $stmt = $pdo->prepare('SELECT customer_id, fullname, phone, password_hash, is_admin FROM customers WHERE phone = ?');
            $stmt->execute([$phone]);
            $user = $stmt->fetch();

            // Note: legacy accounts created before passwords existed
            // (password_hash IS NULL) can no longer log in directly —
            // that used to let anyone in just by knowing a phone number.
            // An admin needs to set a password for them via Admin > Users.
            if ($user && $user['password_hash'] !== null && password_verify($password, $user['password_hash'])) {
                clearLoginAttempts($phone);
                regenerateSession();
                $_SESSION['user_id'] = $user['customer_id'];
                $_SESSION['fullname'] = $user['fullname'];
                $_SESSION['phone'] = $user['phone'];
                $_SESSION['is_admin'] = (bool) $user['is_admin'];
                flashSet('Welcome back, ' . $user['fullname'] . '!');
                header('Location: ' . popRedirectAfterLogin());
                exit;
            }

            registerLoginAttempt($phone);
            $errors[] = ($user && $user['password_hash'] === null)
                ? 'This account needs a password set by an admin before you can log in.'
                : 'Invalid phone number or password.';
        }
    }
}
?>

<?php require __DIR__ . '/includes/header.php'; ?>

<section class="auth">
  <div class="auth__box">
    <h2 class="auth__title">Welcome back</h2>
    <p class="auth__subtitle">Log in to your WTF Lounge account</p>

    <?php if (!empty($errors)): ?>
      <div class="form-errors">
        <ul><?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <form method="post" action="login.php" class="auth__form">
      <?= csrfField() ?>
      <div class="auth__field">
        <label for="phone">Phone Number</label>
        <input type="tel" id="phone" name="phone" required value="<?= h($_POST['phone'] ?? '') ?>" placeholder="e.g., 0917-123-4567">
      </div>
      <div class="auth__field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
      </div>
      <button type="submit" class="btn btn--primary btn--lg">Log in</button>
    </form>
    <p class="auth__footer"><a href="forgot_password.php">Forgot your password?</a></p>
    <p class="auth__footer">
      Don't have an account? <a href="register.php">Register</a>
    </p>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>