<?php
// reset_admin.php — One-time script to fix/create the admin account.
// Visit: http://localhost/SWENG/reset_admin.php
// DELETE this file after use.

require_once 'config.php';

$email    = 'admin@cognitiveai.local';
$username = 'admin';
$password = 'Admin@1234';
$hash     = password_hash($password, PASSWORD_DEFAULT);

// Check if admin already exists
$check = sqlsrv_query($conn, "SELECT ACCOUNT_ID FROM ACCOUNTS WHERE ROLE = 'admin'");
$existing = sqlsrv_fetch_array($check, SQLSRV_FETCH_ASSOC);

if ($existing) {
    // Update the password with a freshly generated hash
    $result = sqlsrv_query($conn,
        "UPDATE ACCOUNTS SET PASSWORD = ? WHERE ROLE = 'admin'",
        [$hash]
    );
    $action = 'Password updated';
} else {
    // Insert fresh admin account
    $result = sqlsrv_query($conn,
        "INSERT INTO ACCOUNTS (USERNAME, EMAIL, PASSWORD, ROLE, DATE_CREATED, SUBSCRIPTION)
         VALUES (?, ?, ?, 'admin', GETDATE(), 'none')",
        [$username, $email, $hash]
    );
    $action = 'Admin account created';
}

if ($result) {
    echo "<h2 style='color:green;font-family:monospace;'>✓ $action successfully.</h2>";
    echo "<p style='font-family:monospace;'>Email: <strong>$email</strong><br>";
    echo "Password: <strong>$password</strong></p>";
    echo "<p style='color:red;font-family:monospace;'><strong>DELETE this file now!</strong><br>";
    echo "Path: " . __FILE__ . "</p>";
} else {
    $err = sqlsrv_errors();
    echo "<h2 style='color:red;font-family:monospace;'>✗ Failed</h2>";
    echo "<pre>" . print_r($err, true) . "</pre>";
}
?>
