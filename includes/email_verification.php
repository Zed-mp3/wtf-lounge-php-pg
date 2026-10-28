<?php
/**
 * Email verification at sign-up. The account is NOT created until the emailed code is entered:
 * the form details wait in the visitor's session (password already hashed), so nothing unverified
 * ever lands in the customers table.
 */
require_once __DIR__ . '/mailer.php';

const REG_CODE_MINUTES        = 10; // how long an emailed code works
const REG_CODE_MAX_TRIES      = 5;  // wrong guesses before the code is dead
const REG_RESEND_SECS         = 60; // minimum gap between emails
const REG_MAX_SENDS_PER_HOUR  = 5;

function pendingRegistration(): ?array
{
    $p = $_SESSION['pending_registration'] ?? null;
    return is_array($p) ? $p : null;
}

function clearPendingRegistration(): void
{
    unset($_SESSION['pending_registration']);
}

/** $details: fullname, phone, email, address, password_hash. True when the first code email went out. */
function beginRegistrationVerification(array $details): bool
{
    $_SESSION['pending_registration'] = $details + [
        'code_hash' => '', 'expires_at' => 0, 'attempts' => 0,
        'sent_at' => 0, 'send_count' => 0, 'window_start' => time(),
    ];
    return issueRegistrationCode() === 'sent';
}

/** Emails a fresh code. Returns 'sent', 'wait', 'limit', 'failed' or 'none'. */
function issueRegistrationCode(): string
{
    $p = pendingRegistration();
    if ($p === null) {
        return 'none';
    }
    $now = time();
    if ($now - (int) $p['sent_at'] < REG_RESEND_SECS) {
        return 'wait';
    }
    if ($now - (int) $p['window_start'] > 3600) {
        $p['window_start'] = $now;
        $p['send_count'] = 0;
    }
    if ((int) $p['send_count'] >= REG_MAX_SENDS_PER_HOUR) {
        return 'limit';
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $name = (string) $p['fullname'];
    $text = 'Hi ' . $name . ', your WTF Lounge verification code is ' . $code . '. It expires in '
        . REG_CODE_MINUTES . " minutes.\n\nIf you did not try to sign up, you can ignore this email.";
    $html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;">'
        . '<h2 style="margin:0 0 12px;">Verify your email</h2>'
        . '<p>Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ', enter this code to finish creating your WTF Lounge account:</p>'
        . '<p style="font-size:32px;font-weight:bold;letter-spacing:6px;margin:16px 0;">' . $code . '</p>'
        . '<p>It expires in ' . REG_CODE_MINUTES . ' minutes. If you did not try to sign up, you can ignore this email.</p>'
        . '</div>';

    if (!sendMail((string) $p['email'], $name, 'Verify your WTF Lounge email', $html, $text)) {
        return 'failed';
    }

    $p['code_hash']  = password_hash($code, PASSWORD_DEFAULT);
    $p['expires_at'] = $now + REG_CODE_MINUTES * 60;
    $p['attempts']   = 0;
    $p['sent_at']    = $now;
    $p['send_count'] = (int) $p['send_count'] + 1;
    $_SESSION['pending_registration'] = $p;
    return 'sent';
}

/** Checks the code. Returns 'ok', 'bad', 'expired', 'locked' or 'none'. */
function checkRegistrationCode(string $code): string
{
    $p = pendingRegistration();
    if ($p === null) {
        return 'none';
    }
    if ($p['code_hash'] === '' || time() > (int) $p['expires_at']) {
        return 'expired';
    }
    if ((int) $p['attempts'] >= REG_CODE_MAX_TRIES) {
        return 'locked';
    }
    if (!preg_match('/^\d{6}$/', $code) || !password_verify($code, $p['code_hash'])) {
        $_SESSION['pending_registration']['attempts'] = (int) $p['attempts'] + 1;
        return ((int) $p['attempts'] + 1) >= REG_CODE_MAX_TRIES ? 'locked' : 'bad';
    }
    return 'ok';
}

/** Creates the verified account and logs the visitor in. Returns the new customer_id. Throws PDOException. */
function completeRegistration(PDO $pdo): int
{
    $p = pendingRegistration();
    if ($p === null) {
        throw new RuntimeException('No pending registration.');
    }
    $stmt = $pdo->prepare('INSERT INTO customers (fullname, phone, email, address, password_hash) VALUES (?, ?, ?, ?, ?) RETURNING customer_id');
    $stmt->execute([$p['fullname'], $p['phone'], $p['email'], $p['address'], $p['password_hash']]);
    $customerId = (int) $stmt->fetchColumn();

    regenerateSession();
    clearPendingRegistration();
    $_SESSION['user_id'] = $customerId;
    $_SESSION['fullname'] = $p['fullname'];
    $_SESSION['phone'] = $p['phone'];
    $_SESSION['is_admin'] = false;
    return $customerId;
}
