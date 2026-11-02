<?php
// Authentication guards. Include at the top of any page that needs them.

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/helpers.php';

function is_logged_in()
{
    return isset($_SESSION['user_id']);
}

/** Protected pages: send visitors who are not logged in to the login page. */
function require_login()
{
    if (!is_logged_in()) {
        redirect('login.php');
    }
}

/** Login/signup pages: send logged-in users straight to the home page. */
function require_guest()
{
    if (is_logged_in()) {
        redirect('home.php');
    }
}
