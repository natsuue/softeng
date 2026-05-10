<?php
// header.php — outputs full page start: DOCTYPE, <html>, <head>, <body>, and the top nav.
// Pages set $pageTitle and optionally $pageStyle (inline CSS string) BEFORE requiring this file.
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$role        = $_SESSION['Role'] ?? null;
$currentPage = basename($_SERVER['PHP_SELF']);
function navLink(string $href, string $icon, string $label, string $current): string {
    $active = ($current === $href) ? 'active-page' : '';
    return "<a class=\"nav-link $active\" href=\"$href\"><i class=\"fas fa-$icon me-1\"></i>$label</a>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'CognitiveAI') ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="style.css">
    <?php if (!empty($pageStyle)): ?>
    <style><?= $pageStyle ?></style>
    <?php endif; ?>
</head>
<body class="<?= htmlspecialchars($bodyClass ?? '') ?>">

<header class="cog-header fixed-top shadow-sm bg-white">
    <nav class="navbar navbar-expand-lg">
        <div class="container">
            <a class="navbar-brand fw-bold" href="<?= $role === 'patient' ? 'patient_portal.php' : ($role === 'admin' ? 'admin.php' : 'dashboard.php') ?>">
                <i class="fas fa-brain me-2 text-primary"></i>COGNITIVE<span class="text-primary">AI</span>
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="mainNavbar">
                <ul class="navbar-nav ms-auto align-items-center gap-1">

                    <?php if ($role === 'practitioner'): ?>
                        <li class="nav-item"><?= navLink('dashboard.php',    'home',         'Dashboard',    $currentPage) ?></li>
                        <li class="nav-item"><?= navLink('product.php',      'brain',        'Assessment',   $currentPage) ?></li>
                        <li class="nav-item"><?= navLink('patients.php',     'users',        'Patients',     $currentPage) ?></li>
                        <li class="nav-item"><?= navLink('appointments.php', 'calendar-alt', 'Appointments', $currentPage) ?></li>
                        <li class="nav-item"><?= navLink('analytics.php',    'chart-bar',    'Analytics',    $currentPage) ?></li>
                        <li class="nav-item"><?= navLink('transactions.php', 'receipt',      'Transactions', $currentPage) ?></li>
                        <li class="nav-item"><?= navLink('subscription.php', 'credit-card',  'Subscription', $currentPage) ?></li>

                    <?php elseif ($role === 'patient'): ?>
                        <li class="nav-item"><?= navLink('patient_portal.php', 'chart-line', 'My Results',   $currentPage) ?></li>
                        <li class="nav-item"><?= navLink('transactions.php',   'receipt',    'Transactions', $currentPage) ?></li>

                    <?php elseif ($role === 'admin'): ?>
                        <li class="nav-item"><?= navLink('admin.php',        'tachometer-alt', 'Dashboard',    $currentPage) ?></li>
                        <li class="nav-item"><?= navLink('analytics.php',    'chart-bar',      'Analytics',    $currentPage) ?></li>
                        <li class="nav-item"><?= navLink('transactions.php', 'receipt',        'Transactions', $currentPage) ?></li>

                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="services.php">Login</a></li>
                    <?php endif; ?>

                    <?php if ($role): ?>
                        <li class="nav-item ms-2">
                            <div class="d-flex align-items-center gap-2">
                                <div class="d-flex align-items-center gap-2 px-3 py-1 rounded-pill"
                                     style="background:#f0f4f8; border:1px solid #e2e8f0;">
                                    <i class="fas fa-user-circle" style="color:var(--brand-primary); font-size:.95rem;"></i>
                                    <span class="fw-semibold" style="font-size:.82rem; color:#1a1a2e; max-width:120px;
                                          overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                        <?= htmlspecialchars($_SESSION['UserName'] ?? '') ?>
                                    </span>
                                    <span class="badge rounded-pill"
                                          style="font-size:.65rem; padding:3px 8px;
                                                 background:<?= $role==='admin' ? '#fee2e2' : ($role==='patient' ? '#e0f2fe' : '#eff6ff') ?>;
                                                 color:<?= $role==='admin' ? '#991b1b' : ($role==='patient' ? '#075985' : '#1a3a5c') ?>;">
                                        <?= ucfirst($role) ?>
                                    </span>
                                </div>
                                <a href="logout.php" class="btn btn-sm"
                                   style="background:#fee2e2; color:#991b1b; border:1px solid #fca5a5;
                                          border-radius:8px; padding:5px 10px;"
                                   title="Sign out">
                                    <i class="fas fa-sign-out-alt"></i>
                                </a>
                            </div>
                        </li>
                    <?php endif; ?>

                </ul>
            </div>
        </div>
    </nav>
</header>
