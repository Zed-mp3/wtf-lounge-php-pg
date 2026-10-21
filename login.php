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

    $phone = trim((string) ($_POST['phone'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (strlen($phone) > 50 || strlen($password) > 1024) { // bound work done on hostile input
        $phone = '';
        $password = '';
    }

    if ($phone === '') {
        $errors[] = 'Phone number is required.';
    }
    if ($password === '') {
        $errors[] = 'Password is required.';
    }

    if (empty($errors)) {
        if (loginThrottled($pdo, $phone)) {
            $errors[] = 'Too many login attempts. Please wait a few minutes and try again.';
        } else {
            $stmt = $pdo->prepare('SELECT customer_id, fullname, phone, password_hash, is_admin FROM customers WHERE phone = ?');
            $stmt->execute([$phone]);
            $user = $stmt->fetch();

            // Always run one bcrypt verification — even for unknown phones or
            // legacy accounts with no password — so response time doesn't reveal
            // whether an account exists.
            $dummyHash = '$2y$10$KPBLU0W74C6eHDwLSXEdLetdwI1dn4icG1dOJxLi9Sy29TQkzi/8i'; // valid bcrypt (cost 10) of a throwaway string
            $hash = ($user && $user['password_hash'] !== null) ? $user['password_hash'] : $dummyHash;
            $passwordOk = password_verify($password, $hash) && $user && $user['password_hash'] !== null;

            if ($passwordOk) {
                clearLoginAttempts($pdo, $phone);

                // Upgrade the stored hash if PHP's default cost/algorithm has moved on.
                if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                    $upd = $pdo->prepare('UPDATE customers SET password_hash = ? WHERE customer_id = ?');
                    $upd->execute([password_hash($password, PASSWORD_DEFAULT), $user['customer_id']]);
                }

                regenerateSession();
                $_SESSION['user_id'] = (int) $user['customer_id'];
                $_SESSION['fullname'] = $user['fullname'];
                $_SESSION['phone'] = $user['phone'];
                $_SESSION['is_admin'] = (bool) $user['is_admin'];
                flashSet('Welcome back, ' . $user['fullname'] . '!');
                header('Location: ' . popRedirectAfterLogin());
                exit;
            }

            recordFailedLogin($pdo, $phone);
            // One message for every failure (wrong password, unknown phone,
            // account without a password) so nothing is leaked about which accounts exist.
            $errors[] = 'Invalid phone number or password.';
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
        <input type="tel" id="phone" name="phone" required maxlength="50" autocomplete="username" value="<?= h($_POST['phone'] ?? '') ?>" placeholder="e.g., 0917-123-4567">
      </div>
      <div class="auth__field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required maxlength="72" autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn--primary btn--lg">Log in</button>
    </form>
    <p class="auth__footer">
      Don't have an account? <a href="register.php">Register</a>
    </p>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
