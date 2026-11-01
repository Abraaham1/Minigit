<?php
// Small helpers shared by all pages.

if (!defined('APP_NAME')) {
    define('APP_NAME', 'MiniGit');
}

/** Escape text for safe output in HTML (prevents XSS). */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Read a POST field as trimmed text ('' if missing or not a string). */
function post_str(string $key, bool $trim = true): string
{
    $v = $_POST[$key] ?? '';
    if (!is_string($v)) {
        return '';
    }
    return $trim ? trim($v) : $v;
}

/** Length in characters (not bytes); works even if the mbstring extension is missing. */
function text_length(string $s): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($s, 'UTF-8');
    }
    return (int)preg_match_all('/./us', $s);
}

function redirect(string $url)
{
    header('Location: ' . $url);
    exit;   // always stop the script after a redirect
}

/* ---------- CSRF protection for every POST form ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    $sent = $_POST['csrf'] ?? '';
    return is_string($sent)
        && isset($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $sent);
}

/* ---------- one-time messages shown after a redirect ---------- */

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
