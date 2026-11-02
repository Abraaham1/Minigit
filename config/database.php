<?php
// Database connection.
// This file lives OUTSIDE public/, so a browser can never request it directly.

$dbHost = 'localhost';
$dbUser = 'minigit_user';
$dbPass = 'Pakistan1947!';   // must match the password in db/schema.sql
$dbName = 'minigit';

// Make mysqli throw exceptions so errors can't be silently ignored
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('Database connection failed.');   // never print credentials or details
}
