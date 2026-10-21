<?php
require_once __DIR__ . '/includes/auth.php';
configureSession();

// The site now requires an account: this is the front door.
// Logged-in visitors go straight to the menu; everyone else
// lands on the login page first.
if (isLoggedIn()) {
    header('Location: menu.php');
} else {
    header('Location: login.php');
}
exit;
