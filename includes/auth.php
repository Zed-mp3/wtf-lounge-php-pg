<?php
/**
 * Session security + auth guards + CSRF helpers.
 *
 * Call configureSession() at the very top of every entry-point file,
 * BEFORE any output and instead of a bare session_start(). Everything
 * else here assumes the session is already active.
 */

function configureSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/* ---------- identity ---------- */

function currentUserId(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

function isAdmin(): bool
{
    return !empty($_SESSION['is_admin']);
}

/**
 * Regenerates the session ID, keeping session data. Call this right
 * after a successful login/registration to prevent session fixation.
 */
function regenerateSession(): void
{
    session_regenerate_id(true);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = basename($_SERVER['SCRIPT_NAME'] ?? 'menu.php');
        header('Location: login.php');
        exit;
    }
}

function requireAdmin(): void
{
    requireLogin();
    if (!isAdmin()) {
        http_response_code(403);
        die('403 — Admin access required.');
    }
}

/**
 * Where to send someone after they log in: back to whatever page
 * bounced them to login.php, or the menu by default.
 */
function popRedirectAfterLogin(): string
{
    $target = $_SESSION['redirect_after_login'] ?? 'menu.php';
    unset($_SESSION['redirect_after_login']);
    // Only allow bare local .php filenames — never an absolute/external URL.
    if (!preg_match('/^[a-zA-Z0-9_\-]+\.php$/', $target)) {
        $target = 'menu.php';
    }
    return $target;
}

/**
 * Validates a "redirect back to" value supplied by the client (e.g. a
 * hidden form field) so it can only point at a local page — never an
 * absolute URL or protocol-relative address (open-redirect guard).
 */
function sanitizeLocalRedirect(?string $target, string $default = 'menu.php'): string
{
    $target = (string) $target;
    if ($target === '' || $target[0] === '/' && substr($target, 0, 2) === '//') {
        return $default;
    }
    // Allow "page.php" or "page.php?a=b&c=d" only — no scheme, no host.
    if (!preg_match('/^[a-zA-Z0-9_\-]+\.php(\?[a-zA-Z0-9_\-=&%.+]*)?$/', $target)) {
        return $default;
    }
    return $target;
}

/* ---------- CSRF ---------- */

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Echo this inside every <form method="post">. */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Call at the top of every POST handler before touching $_POST data. */
function csrfVerify(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if ($token === '' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(400);
        die('Your session expired or this form was resubmitted. Please go back and try again.');
    }
}

/* ---------- login throttling (per phone number, session-scoped) ---------- */

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW_SECS  = 300;

function loginAttemptsExceeded(string $key): bool
{
    $bucket = $_SESSION['login_attempts'][$key] ?? null;
    if (!$bucket || (time() - $bucket['first']) > LOGIN_WINDOW_SECS) {
        return false;
    }
    return $bucket['count'] >= LOGIN_MAX_ATTEMPTS;
}

function registerLoginAttempt(string $key): void
{
    $bucket = $_SESSION['login_attempts'][$key] ?? null;
    if (!$bucket || (time() - $bucket['first']) > LOGIN_WINDOW_SECS) {
        $bucket = ['count' => 0, 'first' => time()];
    }
    $bucket['count']++;
    $_SESSION['login_attempts'][$key] = $bucket;
}

function clearLoginAttempts(string $key): void
{
    unset($_SESSION['login_attempts'][$key]);
}
