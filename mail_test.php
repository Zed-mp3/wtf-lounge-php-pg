<?php
// Local-only mail check. Open http://localhost:3000/mail_test.php (it does nothing on a real server).
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/includes/mailer.php';

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$username = mailSetting('username');
$password = str_replace(' ', '', mailSetting('password'));
$sent = null;
if (isset($_GET['send']) && $username !== '') {
    // Always sends to the sender's own address, never to anyone else.
    $sent = sendMail($username, 'Test', 'WTF Lounge test email', '<p>It works.</p>', 'It works.');
}
?>
<!doctype html>
<meta charset="utf-8">
<title>Mail test</title>
<body style="font-family:Arial,sans-serif;max-width:640px;margin:40px auto;line-height:1.6;">
<h2>Mail test</h2>
<ul>
  <li>config/mail.php found: <b><?= is_file(__DIR__ . '/config/mail.php') ? 'yes' : 'no' ?></b></li>
  <li>Sender (username): <b><?= $username !== '' ? e(maskEmail($username)) : 'NOT SET' ?></b></li>
  <li>App password: <b><?= $password !== '' ? 'set (' . strlen($password) . ' characters, should be 16)' : 'NOT SET' ?></b></li>
  <li>Way of sending: <b><?= e(mailTransport()) ?></b></li>
  <li>PHPMailer found: <b><?= mailLoadLibrary() ? 'yes' : 'NO' ?></b></li>
  <li>Email on in the site: <b><?= mailConfigured() ? 'yes' : 'NO (fill in the settings)' ?></b></li>
</ul>
<?php if ($sent === null): ?>
  <p><a href="mail_test.php?send=1">Send a test email to yourself</a></p>
<?php elseif ($sent): ?>
  <p style="color:green"><b>Sent.</b> Check the inbox (and spam) of <?= e($username) ?>.</p>
<?php else: ?>
  <p style="color:#b00020"><b>Failed:</b> <?= e(mailLastError()) ?></p>
<?php endif; ?>
</body>
