<?php
/**
 * Session security + auth guards + CSRF helpers.
 *
 * Call configureSession() at the very top of every entry-point file,
 * BEFORE any output and instead of a bare session_start(). Everything
 * else here assumes the session is already active.
 */

const SESSION_IDLE_SECS     = 1800;   // log out after 30 min of inactivity
const SESSION_ABSOLUTE_SECS = 28800;  // ...and never keep a session longer than 8 h

function isHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** Per-request random nonce used by the Content-Security-Policy and inline <script> tags. */
function cspNonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }
    return $nonce;
}

/**
 * Sends browser-side protections on every response: CSP, clickjacking,
 * MIME-sniffing, referrer and HSTS (HTTPS only).
 */
function sendSecurityHeaders(): void
{
    if (headers_sent()) {
        return;
    }
    $nonce = cspNonce();
    header("Content-Security-Policy: "
        . "default-src 'self'; "
        . "script-src 'self' 'nonce-$nonce'; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' https://fonts.gstatic.com; "
        . "img-src 'self' data: https:; "
        . "connect-src 'self'; "
        . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    if (isHttps()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function configureSession(): void
{
    sendSecurityHeaders();

    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');   // reject session IDs the server never issued
    ini_set('session.use_only_cookies', '1');  // never accept IDs from the URL
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => isHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $now = time();
    if (isset($_SESSION['last_activity'], $_SESSION['created_at'])
        && (($now - $_SESSION['last_activity']) > SESSION_IDLE_SECS
            || ($now - $_SESSION['created_at']) > SESSION_ABSOLUTE_SECS)) {
        // Expired: wipe everything (including the login) and start clean.
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['created_at']    = $_SESSION['created_at'] ?? $now;
    $_SESSION['last_activity'] = $now;
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
    $_SESSION['created_at'] = time();
    unset($_SESSION['csrf_token']); // fresh token for the new (logged-in) session
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

/** If the browser sent an Origin header, it must be this site. */
function originMatchesHost(): bool
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '' || $origin === 'null') {
        return $origin === ''; // no header (older browsers / same-origin GET) is fine; literal "null" is not
    }
    $host = parse_url($origin, PHP_URL_HOST);
    $port = parse_url($origin, PHP_URL_PORT);
    $originHost = $host . ($port ? ':' . $port : '');
    $serverHost = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '';
    return $host !== null && strcasecmp($originHost, $serverHost) === 0;
}

function csrfVerifyAjax($token): bool
{
    return is_string($token) && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token) && originMatchesHost();
}

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
    if (!is_string($token) || $token === '' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token) || !originMatchesHost()) {
        http_response_code(400);
        die('Your session expired or this form was resubmitted. Please go back and try again.');
    }
}

/* ---------- login throttling ----------
 * Stored in the login_attempts table (sql/security_upgrade.sql) so an attacker
 * can't reset the counter by throwing away their session cookie. If the table
 * hasn't been created yet, falls back to the old per-session counter.
 */

const LOGIN_MAX_ATTEMPTS_PHONE = 5;    // per phone number
const LOGIN_MAX_ATTEMPTS_IP    = 20;   // per client IP
const LOGIN_WINDOW_SECS        = 900;  // 15-minute rolling window

/** Client IP. Behind Render's proxy the last X-Forwarded-For hop is the one the proxy itself appended. */
function clientIp(): string
{
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        $parts = array_map('trim', explode(',', $xff));
        $ip = end($parts);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Normalises a phone number so "0917-123-4567" and "09171234567" share one throttle bucket. */
function throttleKeyForPhone(string $phone): string
{
    return 'p:' . hash('sha256', preg_replace('/\D+/', '', $phone));
}

function loginThrottled(PDO $pdo, string $phone): bool
{
    $keys = [[throttleKeyForPhone($phone), LOGIN_MAX_ATTEMPTS_PHONE], ['i:' . clientIp(), LOGIN_MAX_ATTEMPTS_IP]];
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE throttle_key = ? AND attempted_at > NOW() - (? * INTERVAL '1 second')"
        );
        foreach ($keys as [$key, $max]) {
            $stmt->execute([$key, LOGIN_WINDOW_SECS]);
            if ((int) $stmt->fetchColumn() >= $max) {
                return true;
            }
        }
        return false;
    } catch (PDOException $e) {
        // Table missing — session-scoped fallback.
        $bucket = $_SESSION['login_attempts'][$keys[0][0]] ?? null;
        return $bucket && (time() - $bucket['first']) <= LOGIN_WINDOW_SECS && $bucket['count'] >= LOGIN_MAX_ATTEMPTS_PHONE;
    }
}

function recordFailedLogin(PDO $pdo, string $phone): void
{
    $phoneKey = throttleKeyForPhone($phone);
    try {
        $stmt = $pdo->prepare('INSERT INTO login_attempts (throttle_key) VALUES (?)');
        $stmt->execute([$phoneKey]);
        $stmt->execute(['i:' . clientIp()]);
        // Opportunistic cleanup (~2% of failures) so the table can't grow forever.
        if (random_int(1, 50) === 1) {
            $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL '1 day'");
        }
    } catch (PDOException $e) {
        $bucket = $_SESSION['login_attempts'][$phoneKey] ?? null;
        if (!$bucket || (time() - $bucket['first']) > LOGIN_WINDOW_SECS) {
            $bucket = ['count' => 0, 'first' => time()];
        }
        $bucket['count']++;
        $_SESSION['login_attempts'][$phoneKey] = $bucket;
    }
}

function clearLoginAttempts(PDO $pdo, string $phone): void
{
    $key = throttleKeyForPhone($phone);
    unset($_SESSION['login_attempts'][$key]);
    try {
        $pdo->prepare('DELETE FROM login_attempts WHERE throttle_key = ?')->execute([$key]);
    } catch (PDOException $e) {
        // table not present yet — nothing to clear
    }
}

/* ---------- password policy ---------- */

/** Returns an error message, or null if the password is acceptable. */
function passwordPolicyError(string $password, string $phone = ''): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (strlen($password) > 72) { // bcrypt silently ignores anything past 72 bytes
        return 'Password must be 72 characters or fewer.';
    }
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits !== '' && str_contains(preg_replace('/\D+/', '', $password), $digits)) {
        return 'Password must not contain your phone number.';
    }
    $common = ['password', '12345678', '123456789', '1234567890', 'qwertyui', 'qwerty123', 'iloveyou', 'password1', 'password123', '11111111', '00000000', 'abc12345', 'wtflounge'];
    if (in_array(strtolower($password), $common, true)) {
        return 'That password is too common. Please choose another.';
    }
    return null;
}
