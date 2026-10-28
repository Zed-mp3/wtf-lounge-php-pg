<?php
/**
 * Sends email through PHPMailer.
 *
 * Settings come from config/mail.php (copy config/mail.example.php; works with XAMPP and
 * `php -S`) or, if that file is missing or a value is blank, from environment variables
 * (use these on Render). Keep real passwords out of this file.
 *
 *   file key        environment variable
 *   username        MAIL_USERNAME     the sender Gmail address
 *   password        MAIL_PASSWORD     a Google App Password (not the Gmail password)
 *   from_name       MAIL_FROM_NAME    default "WTF Lounge"
 *   from            MAIL_FROM         default = username
 *   host            MAIL_HOST         default smtp.gmail.com
 *   port            MAIL_PORT         default 587
 *   encryption      MAIL_ENCRYPTION   tls (default) or ssl
 *   transport       MAIL_TRANSPORT    smtp or gmail_api (default: gmail_api if a refresh token is set)
 *   mode            MAIL_MODE         "log" = write the email to the server log, send nothing
 *   google_client_id / google_client_secret / google_refresh_token
 *                   GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET / GOOGLE_REFRESH_TOKEN
 *                   Gmail API over HTTPS, for Render's free plan (it blocks SMTP).
 */

use PHPMailer\PHPMailer\PHPMailer;

const MAIL_ENV_NAMES = [
    'username'            => 'MAIL_USERNAME',
    'password'            => 'MAIL_PASSWORD',
    'from_name'           => 'MAIL_FROM_NAME',
    'from'                => 'MAIL_FROM',
    'host'                => 'MAIL_HOST',
    'port'                => 'MAIL_PORT',
    'encryption'          => 'MAIL_ENCRYPTION',
    'transport'           => 'MAIL_TRANSPORT',
    'mode'                => 'MAIL_MODE',
    'google_client_id'    => 'GOOGLE_CLIENT_ID',
    'google_client_secret' => 'GOOGLE_CLIENT_SECRET',
    'google_refresh_token' => 'GOOGLE_REFRESH_TOKEN',
];

/** One setting: config/mail.php first, then the environment variable, else ''. */
function mailSetting(string $key): string
{
    static $file = null;
    if ($file === null) {
        $path = __DIR__ . '/../config/mail.php';
        $loaded = is_file($path) ? include $path : [];
        $file = is_array($loaded) ? $loaded : [];
    }
    $value = trim((string) ($file[$key] ?? ''));
    if ($value !== '') {
        return $value;
    }
    $env = MAIL_ENV_NAMES[$key] ?? '';
    return $env !== '' ? trim((string) getenv($env)) : '';
}

/** The reason the last sendMail() failed (for mail_test.php and the server log). */
function mailLastError(?string $set = null): string
{
    static $last = '';
    if ($set !== null) {
        $last = $set;
        error_log('Mail send failed: ' . $set);
    }
    return $last;
}

function mailTransport(): string
{
    $forced = strtolower(mailSetting('transport'));
    if ($forced === 'smtp' || $forced === 'gmail_api') {
        return $forced;
    }
    return mailSetting('google_refresh_token') !== '' ? 'gmail_api' : 'smtp';
}

function mailConfigured(): bool
{
    if (strtolower(mailSetting('mode')) === 'log') {
        return true;
    }
    if (mailSetting('username') === '') {
        return false;
    }
    if (mailTransport() === 'gmail_api') {
        return mailSetting('google_client_id') !== ''
            && mailSetting('google_client_secret') !== ''
            && mailSetting('google_refresh_token') !== '';
    }
    return mailSetting('password') !== '';
}

/** Loads PHPMailer: Composer's vendor/ folder, or a phpmailer/src folder copied into the project. */
function mailLoadLibrary(): bool
{
    if (class_exists(PHPMailer::class, false)) {
        return true;
    }
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
        return true;
    }
    $src = __DIR__ . '/../phpmailer/src/';
    if (is_file($src . 'PHPMailer.php')) {
        require_once $src . 'Exception.php';
        require_once $src . 'PHPMailer.php';
        require_once $src . 'SMTP.php';
        return true;
    }
    return false;
}

/** Sends one email. True when Gmail / the mail server accepted it. On failure see mailLastError(). */
function sendMail(string $to, string $toName, string $subject, string $htmlBody, string $textBody): bool
{
    if (strtolower(mailSetting('mode')) === 'log') {
        error_log('[Mail test mode] to ' . $to . ' | ' . $subject . ' | ' . $textBody);
        return true;
    }
    if (!mailLoadLibrary()) {
        mailLastError('PHPMailer is not installed. Run "composer install" in the project folder.');
        return false;
    }

    $username = mailSetting('username');
    $mail = null;

    try {
        $mail = new PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(mailSetting('from') ?: $username, mailSetting('from_name') ?: 'WTF Lounge');
        $mail->addAddress($to, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $textBody;

        if (mailTransport() === 'gmail_api') {
            return sendViaGmailApi($mail);
        }

        $encryption = strtolower(mailSetting('encryption') ?: 'tls');
        $mail->isSMTP();
        $mail->Host       = mailSetting('host') ?: 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $username;
        $mail->Password   = str_replace(' ', '', mailSetting('password')); // Google shows app passwords with spaces
        $mail->SMTPSecure = $encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int) (mailSetting('port') ?: ($encryption === 'ssl' ? 465 : 587));
        $mail->Timeout    = 10;
        $mail->send();
        return true;
    } catch (Throwable $e) {
        $detail = ($mail !== null && $mail->ErrorInfo !== '') ? $mail->ErrorInfo : $e->getMessage();
        mailLastError('SMTP: ' . $detail);
        return false;
    }
}

/** POST and return [httpStatus, body]; status 0 means the request itself failed. */
function mailHttpPost(string $url, string $body, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, is_string($response) ? $response : ''];
}

/** PHPMailer builds the message; Gmail's HTTPS API delivers it (port 443, so Render's SMTP block does not apply). */
function sendViaGmailApi(PHPMailer $mail): bool
{
    // isSMTP() only makes PHPMailer include the To and Subject headers; nothing is sent over SMTP here.
    $mail->isSMTP();
    if (!$mail->preSend()) {
        mailLastError('Gmail API: could not build the message: ' . $mail->ErrorInfo);
        return false;
    }
    $raw = rtrim(strtr(base64_encode($mail->getSentMIMEMessage()), '+/', '-_'), '=');

    [$status, $body] = mailHttpPost(
        'https://oauth2.googleapis.com/token',
        http_build_query([
            'client_id'     => mailSetting('google_client_id'),
            'client_secret' => mailSetting('google_client_secret'),
            'refresh_token' => mailSetting('google_refresh_token'),
            'grant_type'    => 'refresh_token',
        ]),
        ['Content-Type: application/x-www-form-urlencoded']
    );
    $data = json_decode($body, true);
    $accessToken = is_array($data) ? ($data['access_token'] ?? null) : null;
    if ($status !== 200 || !is_string($accessToken)) {
        mailLastError('Gmail API: no access token, HTTP ' . $status . ': ' . substr($body, 0, 300));
        return false;
    }

    [$status, $body] = mailHttpPost(
        'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
        json_encode(['raw' => $raw]),
        ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json']
    );
    if ($status < 200 || $status >= 300) {
        mailLastError('Gmail API: HTTP ' . $status . ': ' . substr($body, 0, 300));
        return false;
    }
    return true;
}

/** j***@gmail.com, for showing on screen without exposing the whole address. */
function maskEmail(string $email): string
{
    $at = strrpos($email, '@');
    if ($at === false || $at < 1) {
        return '***';
    }
    return $email[0] . '***' . substr($email, $at);
}
