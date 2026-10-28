<?php
/**
 * Forgot-password flow for phone-number accounts (no email needed):
 *   1. Customer enters their phone on forgot_password.php        -> 'requested'
 *   2. Admin confirms it is really them, then makes a one-time
 *      link in Admin > Users                                      -> 'link_issued'
 *   3. Admin sends the link (text / Messenger / call); the customer
 *      opens it and sets a new password                           -> 'used'
 * Only a SHA-256 hash of the token is stored, so a database leak cannot be
 * turned into working reset links. Needs sql/password_resets.sql.
 */

const RESET_LINK_HOURS = 24;

/** Customer asked for a reset. Silent when the phone is unknown (no account enumeration). */
function requestPasswordReset(PDO $pdo, string $phone): void
{
    $stmt = $pdo->prepare('SELECT customer_id FROM customers WHERE phone = ?');
    $stmt->execute([$phone]);
    $customerId = $stmt->fetchColumn();
    if ($customerId === false) {
        return;
    }

    // One open row per customer. A repeat request only refreshes the time; it never cancels a link the admin already made.
    $stmt = $pdo->prepare(
        "INSERT INTO password_resets (customer_id, status) VALUES (?, 'requested')
         ON CONFLICT (customer_id) WHERE status IN ('requested', 'link_issued')
         DO UPDATE SET requested_at = now() WHERE password_resets.status = 'requested'"
    );
    $stmt->execute([(int) $customerId]);
}

/** Admin: makes a fresh one-time link for a customer. Returns the raw token (shown once, never stored). */
function issuePasswordResetLink(PDO $pdo, int $customerId): string
{
    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare(
        "INSERT INTO password_resets (customer_id, status, token_hash, issued_at, expires_at)
         VALUES (?, 'link_issued', ?, now(), now() + (CAST(? AS integer) * interval '1 hour'))
         ON CONFLICT (customer_id) WHERE status IN ('requested', 'link_issued')
         DO UPDATE SET status = 'link_issued', token_hash = EXCLUDED.token_hash,
                       issued_at = now(), expires_at = EXCLUDED.expires_at"
    );
    $stmt->execute([$customerId, hash('sha256', $token), RESET_LINK_HOURS]);
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
