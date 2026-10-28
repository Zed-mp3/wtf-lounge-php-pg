<?php
/**
 * Forgot-password flow for phone-number accounts (no email needed):
 *   1. Customer enters their phone on forgot_password.php        -> 'requested'
 *   2. Admin confirms it is really them, then makes a one-time
 *      link in Admin > Users                                      -> 'link_issued'
 *   3. Admin sends the link (text / Messenger / call); the customer
 *      opens it and sets a new password                           -> 'used'
 *
 * When email is configured (includes/mailer.php) the customer can skip the admin:
 *   1. forgot_password.php emails a 6-digit code to the address on file  -> password_reset_codes
 *   2. verify_code.php checks the code and hands over a short-lived one-time link
 *   3. reset_password.php sets the new password, same as the admin flow
 * If the email cannot be sent, the request lands in the admin list instead.
 *
 * Only a SHA-256 hash of the token is stored, so a database leak cannot be
 * turned into working reset links. Needs sql/password_resets.sql.
 */

const RESET_LINK_HOURS = 24;

const RESET_CODE_MINUTES            = 10;  // how long a texted code works
const RESET_CODE_MAX_TRIES          = 5;   // wrong guesses before the code is dead
const RESET_CODE_RESEND_SECS        = 60;  // minimum gap between texts to one customer
const RESET_CODE_MAX_SENDS_PER_HOUR = 5;   // caps email spam to one address
const RESET_LINK_MINUTES_AFTER_CODE = 15;  // the link handed over once the code checks out

/** Customer asked for a reset. Silent when the phone is unknown (no account enumeration). */
function requestPasswordReset(PDO $pdo, string $phone): void
{
    $stmt = $pdo->prepare('SELECT customer_id FROM customers WHERE phone = ?');
    $stmt->execute([$phone]);
    $customerId = $stmt->fetchColumn();
    if ($customerId === false) {
        return;
    }
    queueAdminResetRequest($pdo, (int) $customerId);
}

/** Puts a customer on the admin's "password reset requests" list. */
function queueAdminResetRequest(PDO $pdo, int $customerId): void
{
    // One open row per customer. A repeat request only refreshes the time; it never cancels a link the admin already made.
    $stmt = $pdo->prepare(
        "INSERT INTO password_resets (customer_id, status) VALUES (?, 'requested')
         ON CONFLICT (customer_id) WHERE status IN ('requested', 'link_issued')
         DO UPDATE SET requested_at = now() WHERE password_resets.status = 'requested'"
    );
    $stmt->execute([$customerId]);
}

/** Admin: makes a fresh one-time link for a customer. Returns the raw token (shown once, never stored). */
function issuePasswordResetLink(PDO $pdo, int $customerId, int $minutes = RESET_LINK_HOURS * 60): string
{
    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare(
        "INSERT INTO password_resets (customer_id, status, token_hash, issued_at, expires_at)
         VALUES (?, 'link_issued', ?, now(), now() + (CAST(? AS integer) * interval '1 minute'))
         ON CONFLICT (customer_id) WHERE status IN ('requested', 'link_issued')
         DO UPDATE SET status = 'link_issued', token_hash = EXCLUDED.token_hash,
                       issued_at = now(), expires_at = EXCLUDED.expires_at"
    );
    $stmt->execute([$customerId, hash('sha256', $token), $minutes]);
    return $token;
}

/** Requests waiting for the admin, plus links that were made but not used yet. */
function getOpenPasswordResets(PDO $pdo): array
{
    return $pdo->query(
        "SELECT r.reset_id, r.customer_id, r.status, r.requested_at, r.expires_at, c.fullname, c.phone
         FROM password_resets r
         JOIN customers c ON c.customer_id = r.customer_id
         WHERE r.status IN ('requested', 'link_issued')
         ORDER BY (r.status = 'requested') DESC, r.requested_at DESC"
    )->fetchAll();
}

function dismissPasswordReset(PDO $pdo, int $resetId): void
{
    $stmt = $pdo->prepare(
        "UPDATE password_resets SET status = 'dismissed', token_hash = NULL
         WHERE reset_id = ? AND status IN ('requested', 'link_issued')"
    );
    $stmt->execute([$resetId]);
}

/** The reset a still-valid token belongs to (customer_id, fullname, phone), or null. */
function findPasswordResetByToken(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT r.reset_id, r.customer_id, c.fullname, c.phone
         FROM password_resets r
         JOIN customers c ON c.customer_id = r.customer_id
         WHERE r.token_hash = ? AND r.status = 'link_issued' AND r.expires_at > now()"
    );
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Uses the token (one time only) and sets the new password. False if the token is no longer valid. */
function completePasswordReset(PDO $pdo, string $token, string $newPassword): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }
    $pdo->beginTransaction();
    try {
        // Claiming the token and changing the password happen together or not at all.
        $stmt = $pdo->prepare(
            "UPDATE password_resets SET status = 'used', used_at = now(), token_hash = NULL
             WHERE token_hash = ? AND status = 'link_issued' AND expires_at > now()
             RETURNING customer_id"
        );
        $stmt->execute([hash('sha256', $token)]);
        $customerId = $stmt->fetchColumn();
        if ($customerId === false) {
            $pdo->rollBack();
            return false;
        }
        setCustomerPassword($pdo, (int) $customerId, $newPassword);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Full URL of reset_password.php for a token, built from the current request (works on Render and locally). */
function passwordResetUrl(string $token): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = preg_replace('/[^A-Za-z0-9.:\-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $dir = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/x/y.php'))), '/');
    // From admin/users.php the app root is one level up; from a root page dirname(dirname()) is already '/' -> ''.
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/reset_password.php?token=' . $token;
}

/** The customer with this email address (case-insensitive): customer_id, fullname, email. Null if none. */
function findCustomerByEmail(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare('SELECT customer_id, fullname, email FROM customers WHERE lower(email) = ? LIMIT 1');
    $stmt->execute([strtolower($email)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Without email set up: puts the customer on the admin's list. Silent when the address is unknown. */
function requestPasswordResetByEmail(PDO $pdo, string $email): void
{
    $customer = findCustomerByEmail($pdo, $email);
    if ($customer !== null) {
        queueAdminResetRequest($pdo, (int) $customer['customer_id']);
    }
}

/**
 * Emails a 6-digit code to the address on file. Never tells the caller whether the address is registered.
 * Falls back to the admin list when the email cannot be sent.
 */
function sendPasswordResetCode(PDO $pdo, string $email): void
{
    $customer = findCustomerByEmail($pdo, $email);
    if ($customer === null) {
        return;
    }
    $customerId = (int) $customer['customer_id'];

    $stmt = $pdo->prepare(
        "SELECT sent_at > now() - (CAST(? AS integer) * interval '1 second') AS too_soon,
                window_started_at > now() - interval '1 hour' AS in_window,
                send_count
         FROM password_reset_codes WHERE customer_id = ?"
    );
    $stmt->execute([RESET_CODE_RESEND_SECS, $customerId]);
    $recent = $stmt->fetch();
    if ($recent) {
        if ($recent['too_soon']) {
            return; // they just got one; the existing code still works
        }
        if ($recent['in_window'] && (int) $recent['send_count'] >= RESET_CODE_MAX_SENDS_PER_HOUR) {
            return;
        }
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $stmt = $pdo->prepare(
        "INSERT INTO password_reset_codes (customer_id, code_hash, expires_at)
         VALUES (?, ?, now() + (CAST(? AS integer) * interval '1 minute'))
         ON CONFLICT (customer_id) DO UPDATE SET
             code_hash = EXCLUDED.code_hash,
             expires_at = EXCLUDED.expires_at,
             attempts = 0,
             sent_at = now(),
             send_count = CASE WHEN password_reset_codes.window_started_at > now() - interval '1 hour'
                               THEN password_reset_codes.send_count + 1 ELSE 1 END,
             window_started_at = CASE WHEN password_reset_codes.window_started_at > now() - interval '1 hour'
                                      THEN password_reset_codes.window_started_at ELSE now() END"
    );
    $stmt->execute([$customerId, password_hash($code, PASSWORD_DEFAULT), RESET_CODE_MINUTES]);

    $name = (string) $customer['fullname'];
    $text = 'Hi ' . $name . ', your WTF Lounge password reset code is ' . $code . '. It expires in '
        . RESET_CODE_MINUTES . " minutes.\n\nIf you did not ask for this, you can ignore this email. Never share this code with anyone.";
    $html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;">'
        . '<h2 style="margin:0 0 12px;">WTF Lounge password reset</h2>'
        . '<p>Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ', use this code to reset your password:</p>'
        . '<p style="font-size:32px;font-weight:bold;letter-spacing:6px;margin:16px 0;">' . $code . '</p>'
        . '<p>It expires in ' . RESET_CODE_MINUTES . ' minutes. If you did not ask for this, you can ignore this email. Never share this code with anyone.</p>'
        . '</div>';

    if (!sendMail((string) $customer['email'], $name, 'Your WTF Lounge password reset code', $html, $text)) {
        $pdo->prepare('DELETE FROM password_reset_codes WHERE customer_id = ?')->execute([$customerId]);
        queueAdminResetRequest($pdo, $customerId);
    }
}

/**
 * Checks an emailed code. On success the code is used up and a short-lived one-time reset token is returned;
 * otherwise null (wrong, expired, too many tries, or unknown address: the caller cannot tell which).
 */
function verifyPasswordResetCode(PDO $pdo, string $email, string $code): ?string
{
    if (!preg_match('/^\d{6}$/', $code)) {
        return null;
    }
    $customer = findCustomerByEmail($pdo, $email);
    if ($customer === null) {
        return null;
    }
    $customerId = (int) $customer['customer_id'];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT code_hash, attempts, CASE WHEN expires_at > now() THEN 1 ELSE 0 END AS live
             FROM password_reset_codes WHERE customer_id = ? FOR UPDATE'
        );
        $stmt->execute([$customerId]);
        $row = $stmt->fetch();

        if (!$row || (int) $row['live'] !== 1 || (int) $row['attempts'] >= RESET_CODE_MAX_TRIES) {
            $pdo->commit();
            return null;
        }
        if (!password_verify($code, $row['code_hash'])) {
            $pdo->prepare('UPDATE password_reset_codes SET attempts = attempts + 1 WHERE customer_id = ?')
                ->execute([$customerId]);
            $pdo->commit();
            return null;
        }

        $pdo->prepare('DELETE FROM password_reset_codes WHERE customer_id = ?')->execute([$customerId]);
        $token = issuePasswordResetLink($pdo, $customerId, RESET_LINK_MINUTES_AFTER_CODE);
        $pdo->commit();
        return $token;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
