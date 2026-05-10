<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (isset($_SESSION['Role'])) { header("Location: index.php"); exit(); }
require_once 'config.php';

$message   = '';
$msgType   = '';
$resetLink = '';

if (isset($_POST['request_reset'])) {
    $input = trim($_POST['identifier'] ?? '');

    if ($input === '') {
        $message = 'Please enter your email or username.';
        $msgType = 'danger';
    } else {
        $stmt = sqlsrv_query($conn,
            "SELECT ACCOUNT_ID, EMAIL, USERNAME, ROLE FROM ACCOUNTS
             WHERE EMAIL = ? OR USERNAME = ?",
            [$input, $input]
        );
        $account = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;

        if (!$account) {
            $message = 'No account found with that email or username.';
            $msgType = 'danger';
        } elseif ($account['ROLE'] === 'patient') {
            $message = 'Patient accounts cannot self-reset passwords. Please contact your assigned clinician.';
            $msgType = 'warning';
        } else {
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Invalidate previous tokens for this account
            sqlsrv_query($conn,
                "UPDATE RESET_TOKENS SET USED = 1 WHERE ACCOUNT_ID = ?",
                [$account['ACCOUNT_ID']]
            );

            sqlsrv_query($conn,
                "INSERT INTO RESET_TOKENS (ACCOUNT_ID, TOKEN, EXPIRES_AT) VALUES (?, ?, ?)",
                [$account['ACCOUNT_ID'], $token, $expires]
            );

            $base      = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
            $resetLink = $base . '/SWENG/reset_password.php?token=' . $token;
            $message   = 'Reset link generated for <strong>' . htmlspecialchars($account['EMAIL']) . '</strong>. '
                       . 'In a production system this would be sent via email.';
            $msgType   = 'success';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | CognitiveAI</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f0f4f8; min-height: 100vh;
               display: flex; align-items: center; justify-content: center; padding: 24px; }
        .card-wrap {
            background: #fff; border-radius: 14px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            padding: 44px 40px; width: 100%; max-width: 420px;
        }
        .brand { display: flex; align-items: center; gap: 10px; margin-bottom: 32px; }
        .brand i { font-size: 1.5rem; color: #1a3a5c; }
        .brand span { font-size: 1.2rem; font-weight: 800; color: #0d1b2a; letter-spacing: .5px; }
        .brand em { color: #1a3a5c; font-style: normal; }
        h2 { font-size: 1.45rem; font-weight: 700; color: #0d1b2a; margin-bottom: 6px; }
        .subtitle { color: #6c757d; font-size: 0.87rem; margin-bottom: 28px; line-height: 1.5; }
        .form-label { font-size: 0.83rem; font-weight: 600; color: #374151; margin-bottom: 5px; }
        .input-wrap { position: relative; }
        .input-wrap i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
                        color: #9ca3af; font-size: 0.85rem; pointer-events: none; }
        .form-control { border: 1.5px solid #d1d5db; border-radius: 8px; padding: 10px 14px 10px 36px;
                        font-size: 0.88rem; transition: border-color .18s, box-shadow .18s; }
        .form-control:focus { border-color: #1a3a5c; box-shadow: 0 0 0 3px rgba(26,58,92,.09); outline: none; }
        .btn-main { width: 100%; padding: 11px; background: #1a3a5c; color: #fff; border: none;
                    border-radius: 8px; font-size: 0.92rem; font-weight: 600; cursor: pointer;
                    transition: background .18s; }
        .btn-main:hover { background: #0d2440; }
        .reset-link-box { background: #f0fdf4; border: 1.5px solid #86efac; border-radius: 8px;
                          padding: 14px 16px; margin-top: 16px; word-break: break-all;
                          font-size: 0.82rem; }
        .reset-link-box a { color: #166534; font-weight: 600; }
        .back-link { display: block; text-align: center; margin-top: 24px; font-size: 0.85rem;
                     color: #1a3a5c; text-decoration: none; font-weight: 500; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="card-wrap">
    <div class="brand">
        <i class="fas fa-brain"></i>
        <span>COGNITIVE<em>AI</em></span>
    </div>

    <h2>Reset Password</h2>
    <p class="subtitle">Enter the email or username associated with your account and we'll generate a reset link.</p>

    <?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?> py-2 small"><?= $message ?></div>
    <?php endif; ?>

    <?php if ($resetLink): ?>
    <div class="reset-link-box">
        <div class="fw-semibold mb-1" style="font-size:.8rem;">Your reset link (valid for 1 hour):</div>
        <a href="<?= htmlspecialchars($resetLink) ?>"><?= htmlspecialchars($resetLink) ?></a>
    </div>
    <?php else: ?>
    <form method="POST">
        <div class="mb-4">
            <label class="form-label">Email or Username</label>
            <div class="input-wrap">
                <i class="fas fa-user"></i>
                <input type="text" name="identifier" class="form-control"
                       placeholder="email@hospital.com or username" required
                       value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>">
            </div>
        </div>
        <button type="submit" name="request_reset" class="btn-main">
            <i class="fas fa-paper-plane me-2"></i>Generate Reset Link
        </button>
    </form>
    <?php endif; ?>

    <a href="services.php" class="back-link">
        <i class="fas fa-arrow-left me-1"></i>Back to Sign In
    </a>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
