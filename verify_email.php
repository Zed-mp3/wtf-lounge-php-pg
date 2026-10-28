<?php
require_once __DIR__ . '/includes/auth.php';
configureSession();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/email_verification.php';

if (isLoggedIn()) {
    header('Location: menu.php');
    exit;
}

$pending = pendingRegistration();
if ($pending === null) {
    header('Location: register.php');
    exit;
}

header('Cache-Control: no-store');

$pageTitle = 'Verify your email — WTF Lounge';
$activeNav = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? 'verify';

    if ($action === 'resend') {
        $result = issueRegistrationCode();
        if ($result === 'sent') {
            flashSet('A new code is on its way to ' . maskEmail((string) $pending['email']) . '.');
            header('Location: verify_email.php');
            exit;
        }
        $errors[] = [
            'wait'   => 'Please wait a minute before asking for another code.',
            'limit'  => 'Too many codes requested. Please try again in an hour.',
            'failed' => 'We could not send the email. Please try again in a moment.',
            'none'   => 'Your sign-up session expired. Please start again.',
        ][$result] ?? 'Something went wrong. Please try again.';
    } else {
        $code = preg_replace('/\D+/', '', (string) ($_POST['code'] ?? ''));
        $result = checkRegistrationCode($code);

        if ($result === 'ok') {
            try {
                completeRegistration($pdo);
                flashSet('Email verified! Welcome to WTF Lounge.');
                header('Location: menu.php');
                exit;
            } catch (PDOException $e) {
                error_log('Verified registration failed: ' . $e->getMessage());
                if ($e->getCode() === '23505') {
                    clearPendingRegistration();
                    flashSet('That phone number or email was registered by someone else in the meantime.');
                    header('Location: register.php');
                    exit;
                }
                $errors[] = 'Registration failed. Please try again in a moment.';
            }
        } elseif ($result === 'expired') {
            $errors[] = 'That code has expired. Press "Send a new code" below.';
        } elseif ($result === 'locked') {
            $errors[] = 'Too many wrong tries. Press "Send a new code" below.';
        } elseif ($result === 'none') {
            header('Location: register.php');
            exit;
        } else {
            $errors[] = 'That code is wrong.';
        }
    }
}
?>

<?php require __DIR__ . '/includes/header.php'; ?>

<section class="auth">
  <div class="auth__box">
    <h2 class="auth__title">Verify your email</h2>
    <p class="auth__subtitle">We emailed a 6-digit code to <?= h(maskEmail((string) $pending['email'])) ?>. Enter it to finish creating your account. It works for <?= (int) REG_CODE_MINUTES ?> minutes. Check your spam folder if you don't see it.</p>

    <?php if (!empty($errors)): ?>
      <div class="form-errors">
        <ul><?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <form method="post" action="verify_email.php" class="auth__form">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="verify">
      <div class="auth__field">
        <label for="code">6-digit code</label>
        <input type="text" id="code" name="code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" autofocus placeholder="123456">
      </div>
      <button type="submit" class="btn btn--primary btn--lg">Verify and create account</button>
    </form>

    <form method="post" action="verify_email.php" class="auth__form" style="margin-top:12px;">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="resend">
      <button type="submit" class="btn btn--outline">Send a new code</button>
    </form>

    <p class="auth__footer">
      Wrong email? <a href="register.php">Start over</a>
    </p>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
