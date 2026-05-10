<?php
// Copy this file to config.php and fill in your database details.

$serverName = "YOUR_SERVER_NAME";        // e.g. LAPTOP-ABC123 or localhost
$connectionOptions = [
    "Database" => "Cognitive",           // your database name
    "Uid"      => "",                    // leave blank for Windows auth
    "PWD"      => ""                     // leave blank for Windows auth
];

$conn = sqlsrv_connect($serverName, $connectionOptions);

if (!$conn) {
    $errors = sqlsrv_errors();
    $msg = isset($errors[0]['message']) ? $errors[0]['message'] : 'Unknown error';
    die("Database connection failed: " . htmlspecialchars($msg));
}

function fmt_date($d, string $format): string {
    if ($d instanceof DateTime) return $d->format($format);
    if (is_string($d) && $d !== '') return date($format, strtotime($d));
    return '—';
}
