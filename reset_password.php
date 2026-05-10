<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (isset($_SESSION['Role'])) { header("Location: index.php"); exit(); }
require_once 'config.php';

$token   = trim($_GET['token'] ?? '');
$error   = '';
$success = '';

// Validate token
$tokenRow = null;
if ($token) {
    $stmt = sqlsrv_query($conn,
        "SELECT RT.TOKEN_ID, RT.ACCOUNT_ID, A.EMAIL, A.USERNAME
         FROM RESET_TOKENS RT
         JOIN ACCOUNTS A ON A.ACCOUNT_ID = RT.ACCOUNT_ID
         WHERE RT.TOKEN = ? AND RT.EXPIRES_AT > GETDATE() AND RT.USED = 0",
        [$token]
    );
    $tokenRow = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
}

if (!$token || !$tokenRow) {
    $error = 'This reset link is invalid or has expired. Please request a new one.';
}

if (!$error && isset($_POST['reset_password'])) {
    $pass    = $_POST['Pass']  ?? '';
    $confirm = $_POST['Cpass'] ?? '';

    if (strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pass !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hashed = password_hash($pass, PASSWORD_DEFAULT);

        sqlsrv_query($conn,
            "UPDATE ACCOUNTS SET PASSWORD = ? WHERE ACCOUNT_ID = ?",
            [$hashed, $tokenRow['ACCOUNT_ID']]
        );
        sqlsrv_query($conn,
            "UPDATE RESET_TOKENS SET USED = 1 WHERE TOKEN = ?",
            [$token]
        );

        $success = 'Password updated successfully. You can now sign in.';
        $tokenRow = null; // Hide form after success
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | CognitiveAI</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f0f4f8; min-height: 100vh;
               display: flex; align-items: center; justify-content: center; padding: 24px; }
        .card-wrap { background: #fff; border-radius: 14px; box-shadow: 0 4px 24px rgba(0,0,0,0.08);
                     padding: 44px 40px; width: 100%; max-width: 420px; }
        .brand { display: flex; align-items: center; gap: 10px; margin-bottom: 32px; }
        .brand i { font-size: 1.5rem; color: #1a3a5c; }
        .brand span { font-size: 1.2rem; font-weight: 800; color: #0d1b2a; letter-spacing: .5px; }
        .brand em { color: #1a3a5c; font-style: normal; }
        h2 { font-size: 1.45rem; font-weight: 700; color: #0d1b2a; margin-bottom: 6px; }
        .subtitle { color: #6c757d; font-size: 0.87rem; margin-bottom: 28px; }
        .form-label { font-size: 0.83rem; font-weight: 600; color: #374151; margin-bottom: 5px; }
        .input-wrap { position: relative; }
        .input-wrap .icon-l { position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
                              color: #9ca3af; font-size: 0.85rem; pointer-events: none; }
        .input-wrap .btn-eye { position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
                               background: none; border: none; cursor: pointer; color: #9ca3af;
                               font-size: 0.88rem; padding: 2px 4px; }
        .input-wrap .btn-eye:hover { color: #374151; }
        .form-control { border: 1.5px solid #d1d5db; border-radius: 8px;
                        padding: 10px 40px 10px 36px; font-size: 0.88rem;
                        transition: border-color .18s, box-shadow .18s; }
        .form-control:focus { border-color: #1a3a5c; box-shadow: 0 0 0 3px rgba(26,58,92,.09); outline: none; }
        .btn-main { width: 100%; padding: 11px; background: #1a3a5c; color: #fff; border: none;
                    border-radius: 8px; font-size: 0.92rem; font-weight: 600; cursor: pointer;
                    transition: background .18s; }
        .btn-main:hover { background: #0d2440; }
        .back-link { display: block; text-align: center; margin-top: 24px; font-size: 0.85rem;
                     color: #1a3a5c; text-decoration: none; font-weight: 500; }
        .back-link:hover { text-decoration: underline; }
        .strength-bar { height: 4px; border-radius: 4px; margin-top: 6px;
                        background: #e5e7eb; overflow: hidden; }
        .strength-fill { height: 100%; width: 0%; border-radius: 4px; transition: width .3s, background .3s; }
    </style>
</head>
<body>
<div class="card-wrap">
    <div class="brand">
        <i class="fas fa-brain"></i>
        <span>COGNITIVE<em>AI</em></span>
    </div>

    <h2>Set New Password</h2>
    <p class="subtitle">Choose a strong password of at least 8 characters.</p>

    <?php if ($error): ?>
    <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success py-2 small"><?= htmlspecialchars($success) ?></div>
    <a href="services.php" class="btn-main d-block text-center text-decoration-none mt-3">
        <i class="fas fa-sign-in-alt me-2"></i>Go to Sign In
    </a>

    <?php elseif ($tokenRow): ?>
    <form method="POST">
        <div class="mb-3">
            <label class="form-label">New Password</label>
            <div class="input-wrap">
                <i class="fas fa-lock icon-l"></i>
                <input type="password" name="Pass" id="newPass" class="form-control"
                       placeholder="Min. 8 characters" required minlength="8"
                       oninput="checkStrength(this.value)">
                <button type="button" class="btn-eye" onclick="togglePass('newPass',this)">
                    <i class="fas fa-eye"></i>
                </button>
            </div>
            <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
            <div id="strengthLabel" class="mt-1" style="font-size:.75rem;color:#6c757d;"></div>
        </div>

        <div class="mb-4">
            <label class="form-label">Confirm Password</label>
            <div class="input-wrap">
                <i class="fas fa-lock icon-l"></i>
                <input type="password" name="Cpass" id="cfmPass" class="form-control"
                       placeholder="Repeat password" required>
                <button type="button" class="btn-eye" onclick="togglePass('cfmPass',this)">
                    <i class="fas fa-eye"></i>
                </button>
            </div>
        </div>

        <button type="submit" name="reset_password" class="btn-main">
            <i class="fas fa-key me-2"></i>Update Password
        </button>
    </form>

    <?php elseif (!$success): ?>
    <a href="forgot_password.php" class="btn-main d-block text-center text-decoration-none">
        Request a New Link
    </a>
    <?php endif; ?>

    <a href="services.php" class="back-link">
        <i class="fas fa-arrow-left me-1"></i>Back to Sign In
    </a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePass(id, btn) {
    const inp = document.getElementById(id);
    const ico = btn.querySelector('i');
    inp.type = inp.type === 'password' ? 'text' : 'password';
    ico.className = inp.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
}
function checkStrength(val) {
    const fill  = document.getElementById('strengthFill');
    const label = document.getElementById('strengthLabel');
    let score = 0;
    if (val.length >= 8)  score++;
    if (val.length >= 12) score++;
    if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
    if (/\d/.test(val))   score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const levels = [
        { pct: '20%',  bg: '#ef4444', text: 'Very weak'  },
        { pct: '40%',  bg: '#f97316', text: 'Weak'       },
        { pct: '60%',  bg: '#eab308', text: 'Fair'       },
        { pct: '80%',  bg: '#22c55e', text: 'Strong'     },
        { pct: '100%', bg: '#15803d', text: 'Very strong'},
    ];
    const l = levels[Math.max(0, score - 1)] || levels[0];
    fill.style.width      = l.pct;
    fill.style.background = l.bg;
    label.textContent     = val.length ? l.text : '';
    label.style.color     = l.bg;
}
</script>
</body>
</html>
