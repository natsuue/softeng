<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (isset($_SESSION['Role'])) {
    $dest = match($_SESSION['Role']) {
        'admin'   => 'admin.php',
        'patient' => 'patient_portal.php',
        default   => 'dashboard.php',
    };
    header("Location: $dest");
    exit();
}
header("Location: services.php");
exit();
