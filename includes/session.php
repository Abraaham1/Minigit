<?php
// Starts the session with safe cookie settings.
// Include this (via auth.php) before any output is sent.

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');   // reject session IDs the server never issued
    ini_set('session.use_only_cookies', '1');  // never accept the ID from the URL

    session_set_cookie_params([
        'lifetime' => 0,          // cookie ends when the browser closes
        'path'     => '/',
        'httponly' => true,       // JavaScript cannot read the cookie
        'samesite' => 'Lax',      // not sent on cross-site POSTs
        // 'secure' => true,      // turn on once the site is served over HTTPS
    ]);

    session_start();
}
