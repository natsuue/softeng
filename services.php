<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// Redirect already-logged-in users
if (isset($_SESSION['Role'])) {
    header("Location: " . match($_SESSION['Role']) {
        'admin'   => 'admin.php',
        'patient' => 'patient_portal.php',
        default   => 'dashboard.php',
    });
    exit();
}

// Auto-login via remember-me cookie
if (isset($_COOKIE['remember_token'])) {
    require_once 'config.php';
    $stmt = sqlsrv_query($conn,
        "SELECT A.ACCOUNT_ID, A.USERNAME, A.EMAIL, A.ROLE, A.SUBSCRIPTION
         FROM REMEMBER_TOKENS RT
         JOIN ACCOUNTS A ON A.ACCOUNT_ID = RT.ACCOUNT_ID
         WHERE RT.TOKEN = ? AND RT.EXPIRES_AT > GETDATE()",
        [$_COOKIE['remember_token']]
    );
    if ($stmt && ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
        $_SESSION['AccountID']    = $row['ACCOUNT_ID'];
        $_SESSION['UserName']     = $row['USERNAME'];
        $_SESSION['Email']        = $row['EMAIL'];
        $_SESSION['Role']         = $row['ROLE'];
        $_SESSION['Subscription'] = $row['SUBSCRIPTION'];
        header("Location: " . match($row['ROLE']) {
            'admin'   => 'admin.php',
            'patient' => 'patient_portal.php',
            default   => 'dashboard.php',
        });
        exit();
    }
}

$activeTab     = $_GET['tab'] ?? 'login';
$loginError    = $_SESSION['login_error']    ?? '';
$registerError = $_SESSION['register_error'] ?? '';
unset($_SESSION['login_error'], $_SESSION['register_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In | CognitiveAI</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; min-height: 100vh; display: flex; background: #f0f4f8; }

        /* ── Left Panel ───────────────────────────────────── */
        .auth-left {
            width: 42%;
            background: linear-gradient(150deg, #0d1b2a 0%, #1a3a5c 55%, #1e4d78 100%);
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 60px 52px;
            color: #fff;
            position: relative;
            overflow: hidden;
            flex-shrink: 0;
        }
        .auth-left::before {
            content: '';
            position: absolute;
            width: 380px; height: 380px;
            background: rgba(255,255,255,0.035);
            border-radius: 50%;
            top: -100px; right: -100px;
        }
        .auth-left::after {
            content: '';
            position: absolute;
            width: 280px; height: 280px;
            background: rgba(255,255,255,0.035);
            border-radius: 50%;
            bottom: -70px; left: -70px;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 56px;
            position: relative;
            z-index: 1;
        }
        .brand i { font-size: 1.9rem; color: #74c0fc; }
        .brand h1 { font-size: 1.55rem; font-weight: 800; letter-spacing: 1px; color: #fff; }
        .brand span { color: #74c0fc; }

        .auth-left h2 {
            font-size: 1.85rem;
            font-weight: 700;
            line-height: 1.35;
            margin-bottom: 14px;
            position: relative; z-index: 1;
        }
        .auth-left .desc {
            color: rgba(255,255,255,0.62);
            font-size: 0.9rem;
            line-height: 1.65;
            margin-bottom: 36px;
            position: relative; z-index: 1;
        }
        .feature-list { list-style: none; position: relative; z-index: 1; }
        .feature-list li {
            display: flex;
            align-items: center;
            gap: 11px;
            color: rgba(255,255,255,0.82);
            font-size: 0.88rem;
            margin-bottom: 13px;
        }
        .feature-list li i { color: #69db7c; font-size: 0.85rem; flex-shrink: 0; }

        .auth-badge {
            margin-top: 48px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 40px;
            padding: 9px 18px;
            font-size: 0.77rem;
            color: rgba(255,255,255,0.65);
            position: relative; z-index: 1;
        }
        .auth-badge i { color: #ffd43b; }

        /* ── Right Panel ──────────────────────────────────── */
        .auth-right {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 48px 40px;
            overflow-y: auto;
        }
        .auth-form-wrap { width: 100%; max-width: 400px; }

        .auth-form-wrap h2 {
            font-size: 1.65rem;
            font-weight: 700;
            color: #0d1b2a;
            margin-bottom: 4px;
        }
        .subtitle { color: #6c757d; font-size: 0.88rem; margin-bottom: 30px; }

        /* ── Form Elements ────────────────────────────────── */
        .form-label { font-size: 0.83rem; font-weight: 600; color: #374151; margin-bottom: 5px; }

        .input-wrap { position: relative; }
        .input-wrap .icon-left {
            position: absolute; left: 12px; top: 50%;
            transform: translateY(-50%);
            color: #9ca3af; font-size: 0.85rem; pointer-events: none;
        }
        .input-wrap .form-control { padding-left: 36px; padding-right: 40px; }
        .input-wrap .btn-eye {
            position: absolute; right: 10px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            color: #9ca3af; font-size: 0.88rem; padding: 2px 4px;
        }
        .input-wrap .btn-eye:hover { color: #374151; }

        .form-control {
            border: 1.5px solid #d1d5db;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 0.88rem;
            transition: border-color 0.18s, box-shadow 0.18s;
            background: #fff;
        }
        .form-control:focus {
            border-color: #1a3a5c;
            box-shadow: 0 0 0 3px rgba(26,58,92,0.09);
            outline: none;
        }

        .remember-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 14px 0 22px;
            font-size: 0.83rem;
        }
        .remember-row label { display: flex; align-items: center; gap: 7px; cursor: pointer; color: #374151; }
        .remember-row input[type=checkbox] { width: 15px; height: 15px; accent-color: #1a3a5c; }
        .remember-row a { color: #1a3a5c; text-decoration: none; font-weight: 500; }
        .remember-row a:hover { text-decoration: underline; }

        .btn-main {
            width: 100%; padding: 11px;
            background: #1a3a5c; color: #fff;
            border: none; border-radius: 8px;
            font-size: 0.92rem; font-weight: 600;
            cursor: pointer; transition: background 0.18s, transform 0.1s;
            letter-spacing: 0.3px;
        }
        .btn-main:hover  { background: #0d2440; }
        .btn-main:active { transform: scale(0.99); }

        .divider {
            display: flex; align-items: center; gap: 12px;
            margin: 22px 0; color: #9ca3af; font-size: 0.78rem;
        }
        .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: #e5e7eb; }

        .switch-link { text-align: center; font-size: 0.85rem; color: #6c757d; }
        .switch-link a { color: #1a3a5c; font-weight: 600; text-decoration: none; cursor: pointer; }
        .switch-link a:hover { text-decoration: underline; }

        .patient-hint {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 12px 15px;
            font-size: 0.79rem;
            color: #1e40af;
            margin-top: 20px;
            line-height: 1.5;
        }

        @media (max-width: 768px) {
            .auth-left  { display: none; }
            .auth-right { padding: 28px 20px; background: #fff; }
        }
    </style>
</head>
<body>

<div class="auth-left">
    <div class="brand">
        <i class="fas fa-brain"></i>
        <h1>COGNITIVE<span>AI</span></h1>
    </div>

    <h2>AI-Powered Cognitive Assessment for Clinicians</h2>
    <p class="desc">
        A clinical tool built for healthcare professionals to assess and monitor patient
        cognitive performance using machine learning.
    </p>

    <ul class="feature-list">
        <li><i class="fas fa-check-circle"></i> Interactive cognitive tests (reaction &amp; memory)</li>
        <li><i class="fas fa-check-circle"></i> ML-powered cognitive scoring engine</li>
        <li><i class="fas fa-check-circle"></i> Patient history &amp; trend tracking</li>
        <li><i class="fas fa-check-circle"></i> Secure multi-role access control</li>
        <li><i class="fas fa-check-circle"></i> Printable assessment reports</li>
    </ul>

    <div class="auth-badge">
        <i class="fas fa-shield-alt"></i>
        For authorized medical personnel only
    </div>
</div>

<div class="auth-right">
<div class="auth-form-wrap">

    <!-- ── LOGIN ──────────────────────────────────────────── -->
    <div id="login-section" class="<?= $activeTab === 'register' ? 'd-none' : '' ?>">
        <h2>Welcome back</h2>
        <p class="subtitle">Sign in to your CognitiveAI account</p>

        <form action="login.php" method="POST" autocomplete="on">

            <div class="mb-3">
                <label class="form-label">Email or Username</label>
                <div class="input-wrap">
                    <i class="fas fa-user icon-left"></i>
                    <input name="Email" type="text" class="form-control"
                           placeholder="email@hospital.com or juan.delacruz.4829"
                           required autocomplete="username">
                </div>
            </div>

            <div class="mb-1">
                <label class="form-label">Password</label>
                <div class="input-wrap">
                    <i class="fas fa-lock icon-left"></i>
                    <input name="Pass" type="password" id="loginPass" class="form-control"
                           placeholder="Your password" required autocomplete="current-password">
                    <button type="button" class="btn-eye" onclick="togglePass('loginPass',this)">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="remember-row">
                <label>
                    <input type="checkbox" name="remember_me">
                    Remember me for 30 days
                </label>
                <a href="forgot_password.php">Forgot password?</a>
            </div>

            <button type="submit" name="Login" class="btn-main">
                <i class="fas fa-sign-in-alt me-2"></i>Sign In
            </button>
        </form>

        <div class="divider">or</div>

        <div class="switch-link">
            New clinician? <a onclick="toggleForm()">Create an account</a>
        </div>

        <div class="patient-hint">
            <i class="fas fa-user-injured me-1"></i>
            <strong>Patient?</strong> Use the <strong>username</strong> and temporary password
            given by your clinician (e.g. <em>juan.delacruz.4829</em>).
        </div>
    </div>

    <!-- ── REGISTER ───────────────────────────────────────── -->
    <div id="register-section" class="<?= $activeTab === 'login' ? 'd-none' : '' ?>">
        <h2>Create Account</h2>
        <p class="subtitle">Register as a clinician or practitioner</p>

        <form action="login.php" method="POST" autocomplete="on">

            <div class="mb-3">
                <label class="form-label">Full Name</label>
                <div class="input-wrap">
                    <i class="fas fa-user-md icon-left"></i>
                    <input name="Uname" type="text" class="form-control"
                           placeholder="Dr. Juan dela Cruz" required autocomplete="name">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Email Address</label>
                <div class="input-wrap">
                    <i class="fas fa-envelope icon-left"></i>
                    <input name="Email" type="email" class="form-control"
                           placeholder="name@hospital.com" required autocomplete="email">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Password</label>
                <div class="input-wrap">
                    <i class="fas fa-lock icon-left"></i>
                    <input name="Pass" type="password" id="regPass" class="form-control"
                           placeholder="Min. 8 characters" required minlength="8"
                           autocomplete="new-password">
                    <button type="button" class="btn-eye" onclick="togglePass('regPass',this)">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Confirm Password</label>
                <div class="input-wrap">
                    <i class="fas fa-lock icon-left"></i>
                    <input name="Cpass" type="password" id="regCpass" class="form-control"
                           placeholder="Repeat password" required autocomplete="new-password">
                    <button type="button" class="btn-eye" onclick="togglePass('regCpass',this)">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <button type="submit" name="Register" class="btn-main">
                <i class="fas fa-user-plus me-2"></i>Create Account
            </button>
        </form>

        <div class="divider">or</div>

        <div class="switch-link">
            Already have an account? <a onclick="toggleForm()">Sign in</a>
        </div>
    </div>

</div><!-- /auth-form-wrap -->
</div><!-- /auth-right -->

<?php if ($loginError): ?>
<script>
document.addEventListener('DOMContentLoaded', () =>
    Swal.fire({ title: 'Login Failed', text: '<?= addslashes($loginError) ?>', icon: 'error', confirmButtonColor: '#1a3a5c' })
);
</script>
<?php endif; ?>
<?php if ($registerError): ?>
<script>
document.addEventListener('DOMContentLoaded', () =>
    Swal.fire({ title: 'Registration Error', text: '<?= addslashes($registerError) ?>', icon: 'warning', confirmButtonColor: '#1a3a5c' })
);
</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleForm() {
    document.getElementById('login-section').classList.toggle('d-none');
    document.getElementById('register-section').classList.toggle('d-none');
}
function togglePass(id, btn) {
    const inp  = document.getElementById(id);
    const icon = btn.querySelector('i');
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.className = 'fas fa-eye-slash';
    } else {
        inp.type = 'password';
        icon.className = 'fas fa-eye';
    }
}
</script>
</body>
</html>
