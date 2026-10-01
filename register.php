<?php
require_once __DIR__ . '/includes/auth.php';
configureSession();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    header('Location: menu.php');
    exit;
}

$pageTitle = 'Register — WTF Lounge';
$activeNav = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();

    $fullname = trim($_POST['fullname'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($fullname === '') {
        $errors[] = 'Full name is required.';
    }
    if ($phone === '') {
        $errors[] = 'Phone number is required.';
    } elseif (!preg_match('/^[\d\-\+\s]{7,20}$/', $phone)) {
        $errors[] = 'Enter a valid phone number.';
    }
    if ($address === '') {
        $errors[] = 'Address is required.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT customer_id FROM customers WHERE phone = ?');
        $stmt->execute([$phone]);
        if ($stmt->fetch()) {
            $errors[] = 'Phone number already registered.';
        }
    }

    if (empty($errors)) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $stmt = $pdo->prepare('INSERT INTO customers (fullname, phone, address, password_hash) VALUES (?, ?, ?, ?) RETURNING customer_id');
            $stmt->execute([$fullname, $phone, $address, $passwordHash]);

            regenerateSession();
            $_SESSION['user_id'] = (int) $stmt->fetchColumn();
            $_SESSION['fullname'] = $fullname;
            $_SESSION['phone'] = $phone;
            $_SESSION['is_admin'] = false;
            flashSet('Registration successful! Welcome to WTF Lounge.');
            header('Location: menu.php');
            exit;
        } catch (PDOException $e) {
            // Never leak raw DB exception text to the browser.
            $errors[] = 'Registration failed. Please try again in a moment.';
        }
    }
}
?>

<?php require __DIR__ . '/includes/header.php'; ?>

<section class="auth">
  <div class="auth__box">
    <h2 class="auth__title">Create your account</h2>
    <p class="auth__subtitle">Join WTF Lounge to save your order history and enjoy faster checkout.</p>

    <?php if (!empty($errors)): ?>
      <div class="form-errors">
        <ul><?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <form method="post" action="register.php" class="auth__form">
      <?= csrfField() ?>
      <div class="auth__field">
        <label for="fullname">Full Name</label>
        <input type="text" id="fullname" name="fullname" required value="<?= h($_POST['fullname'] ?? '') ?>">
      </div>
      <div class="auth__field">
        <label for="phone">Phone Number</label>
        <input type="tel" id="phone" name="phone" required value="<?= h($_POST['phone'] ?? '') ?>" placeholder="e.g., 0917-123-4567">
      </div>
      <div class="auth__field">
        <label for="address">Address</label>
        <textarea id="address" name="address" required><?= h($_POST['address'] ?? '') ?></textarea>
      </div>
      <div class="auth__field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required minlength="8">
      </div>
      <div class="auth__field">
        <label for="confirm_password">Confirm Password</label>
        <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
      </div>
      <button type="submit" class="btn btn--primary btn--lg">Register</button>
    </form>
    <p class="auth__footer">
      Already have an account? <a href="login.php">Log in</a>
    </p>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
