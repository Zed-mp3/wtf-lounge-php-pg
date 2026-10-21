<?php
require_once __DIR__ . '/includes/auth.php';
configureSession();
require_once __DIR__ . '/includes/functions.php';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

configureSession();
flashSet('You\'ve been logged out. See you again soon!');
header('Location: login.php');
exit;
