<?php
require_once __DIR__ . '/../includes/auth.php';

// Logout only works as a POST with a valid CSRF token (the button in the header),
// so another website cannot log a user out by loading this URL.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid()) {
    redirect(is_logged_in() ? 'home.php' : 'login.php');
}

$_SESSION = [];   // clear the data held in this request

// Delete the session cookie in the browser
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

session_destroy();   // delete the server-side session

redirect('login.php');
