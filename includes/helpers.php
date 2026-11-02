<?php
// Small helpers shared by all pages.

date_default_timezone_set('Asia/Karachi');   // times are shown in Pakistan time

if (!defined('APP_NAME')) {
    define('APP_NAME', 'MiniGit');
}

/** Escape text for safe output in HTML (prevents XSS). */
function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Read a POST field as text ('' if missing or not a string). */
function post_str($key, $trim = true)
{
    if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
        return '';
    }
    return $trim ? trim($_POST[$key]) : $_POST[$key];
}

/** Read a GET field as trimmed text ('' if missing). */
function get_str($key)
{
    if (!isset($_GET[$key]) || !is_string($_GET[$key])) {
        return '';
    }
    return trim($_GET[$key]);
}

/** Length in characters (not bytes); works even if the mbstring extension is missing. */
function text_length($s)
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($s, 'UTF-8');
    }
    return (int)preg_match_all('/./us', $s);
}

function redirect($url)
{
    header('Location: ' . $url);
    exit;   // always stop the script after a redirect
}

/* ---------- CSRF protection for every POST form ---------- */

function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid()
{
    $sent = isset($_POST['csrf']) ? $_POST['csrf'] : '';
    return is_string($sent)
        && isset($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $sent);
}

/* ---------- one-time messages shown after a redirect ---------- */

function flash_set($type, $message)
{
    $_SESSION['flash'] = array('type' => $type, 'message' => $message);
}

function flash_get()
{
    $f = isset($_SESSION['flash']) ? $_SESSION['flash'] : null;
    unset($_SESSION['flash']);
    return $f;
}
