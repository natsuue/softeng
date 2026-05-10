<?php
// login.php — pure POST handler, no HTML output.
session_start();
require_once 'config.php';

// ============================================================
// REGISTER
// ============================================================
if (isset($_POST['Register'])) {
    $name    = trim($_POST['Uname']  ?? '');
    $email   = trim($_POST['Email']  ?? '');
    $pass    =      $_POST['Pass']   ?? '';
    $confirm =      $_POST['Cpass']  ?? '';

    $stmt = sqlsrv_query($conn, "SELECT 1 FROM ACCOUNTS WHERE EMAIL = ?", [$email]);
    if (sqlsrv_has_rows($stmt)) {
        $_SESSION['register_error'] = 'That email is already registered.';
        header("Location: services.php?tab=register");
        exit();
    }

    if ($pass !== $confirm) {
        $_SESSION['register_error'] = 'Passwords do not match.';
        header("Location: services.php?tab=register");
        exit();
    }

    if (strlen($pass) < 8) {
        $_SESSION['register_error'] = 'Password must be at least 8 characters.';
        header("Location: services.php?tab=register");
        exit();
    }

    $hashed = password_hash($pass, PASSWORD_DEFAULT);
    $result = sqlsrv_query($conn,
        "INSERT INTO ACCOUNTS (USERNAME, EMAIL, PASSWORD, ROLE, DATE_CREATED, SUBSCRIPTION)
         VALUES (?, ?, ?, 'practitioner', GETDATE(), 'free')",
        [$name, $email, $hashed]
    );

    if (!$result) {
        $err = sqlsrv_errors();
        $_SESSION['register_error'] = 'Registration failed: ' . ($err[0]['message'] ?? 'Unknown error');
        header("Location: services.php?tab=register");
        exit();
    }

    $row = sqlsrv_fetch_array(
        sqlsrv_query($conn, "SELECT ACCOUNT_ID, ROLE, SUBSCRIPTION FROM ACCOUNTS WHERE EMAIL = ?", [$email]),
        SQLSRV_FETCH_ASSOC
    );

    $_SESSION['Email']        = $email;
    $_SESSION['UserName']     = $name;
    $_SESSION['Role']         = 'practitioner';
    $_SESSION['AccountID']    = $row['ACCOUNT_ID'];
    $_SESSION['Subscription'] = 'free';

    header("Location: subscription.php?welcome=1");
    exit();
}

// ============================================================
// LOGIN
// ============================================================
if (isset($_POST['Login'])) {
    $input = trim($_POST['Email'] ?? '');
    $pass  =      $_POST['Pass']  ?? '';

    $stmt = sqlsrv_query($conn,
        "SELECT ACCOUNT_ID, USERNAME, EMAIL, PASSWORD, ROLE, SUBSCRIPTION
         FROM ACCOUNTS WHERE EMAIL = ? OR USERNAME = ?",
        [$input, $input]
    );
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    if (!$row || !password_verify($pass, $row['PASSWORD'])) {
        $_SESSION['login_error'] = 'Invalid email/username or password.';
        header("Location: services.php");
        exit();
    }

    $_SESSION['Email']        = $row['EMAIL'];
    $_SESSION['UserName']     = $row['USERNAME'];
    $_SESSION['Role']         = $row['ROLE'];
    $_SESSION['AccountID']    = $row['ACCOUNT_ID'];
    $_SESSION['Subscription'] = $row['SUBSCRIPTION'];

    // ── Remember Me ──────────────────────────────────────────
    if (!empty($_POST['remember_me'])) {
        $token     = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        // Remove old tokens for this account and any expired tokens
        sqlsrv_query($conn,
            "DELETE FROM REMEMBER_TOKENS WHERE ACCOUNT_ID = ? OR EXPIRES_AT < GETDATE()",
            [$row['ACCOUNT_ID']]
        );

        sqlsrv_query($conn,
            "INSERT INTO REMEMBER_TOKENS (ACCOUNT_ID, TOKEN, EXPIRES_AT) VALUES (?, ?, ?)",
            [$row['ACCOUNT_ID'], $token, $expiresAt]
        );

        setcookie('remember_token', $token, [
            'expires'  => time() + (30 * 24 * 60 * 60),
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    // ── Redirect by role ─────────────────────────────────────
    if ($row['ROLE'] === 'admin') {
        header("Location: admin.php");
        exit();
    }

    if ($row['ROLE'] === 'patient') {
        header("Location: patient_portal.php");
        exit();
    }

    // Practitioner — check for active subscription
    $subCheck = sqlsrv_query($conn,
        "SELECT 1 FROM SUBSCRIPTIONS
         WHERE ACCOUNT_ID = ? AND STATUS = 'active' AND END_DATE > GETDATE()",
        [$row['ACCOUNT_ID']]
    );
    header("Location: " . (sqlsrv_has_rows($subCheck) ? 'dashboard.php' : 'subscription.php'));
    exit();
}

header("Location: services.php");
exit();
