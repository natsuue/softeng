<?php
session_start();
require_once 'config.php';

// Clear remember-me token from DB and browser
if (isset($_COOKIE['remember_token'])) {
    sqlsrv_query($conn,
        "DELETE FROM REMEMBER_TOKENS WHERE TOKEN = ?",
        [$_COOKIE['remember_token']]
    );
    setcookie('remember_token', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

session_unset();
session_destroy();
header("Location: services.php");
exit();
