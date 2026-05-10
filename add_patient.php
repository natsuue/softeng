<?php
// add_patient.php — AJAX/fetch handler (JSON response, no HTML).
// Called by product.php when practitioner adds a new patient.

session_start();
header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['Role']) || $_SESSION['Role'] !== 'practitioner') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

require_once 'config.php';

$fullName = trim($_POST['full_name'] ?? '');
if ($fullName === '') {
    echo json_encode(['success' => false, 'error' => 'Patient name is required.']);
    exit();
}

$practitionerId = $_SESSION['AccountID'];

// ── Generate unique credentials ───────────────────────────
$nameParts    = preg_split('/\s+/', strtolower($fullName));
$baseUsername = implode('.', array_map(fn($p) => preg_replace('/[^a-z0-9]/', '', $p), $nameParts));
$baseUsername = $baseUsername ?: 'patient';

// Ensure username is unique
$suffix   = rand(1000, 9999);
$username = $baseUsername . '.' . $suffix;

// Avoid collision (rare but possible)
$checkLoop = 0;
while ($checkLoop < 5) {
    $dup = sqlsrv_query($conn, "SELECT 1 FROM ACCOUNTS WHERE USERNAME = ?", [$username]);
    if (!sqlsrv_has_rows($dup)) break;
    $username = $baseUsername . '.' . rand(1000, 9999);
    $checkLoop++;
}

// Temporary password: readable format e.g. Cog-XK4P7
$chars   = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$tempPass = 'Cog-' . substr(str_shuffle($chars . $chars), 0, 5);
$hashed  = password_hash($tempPass, PASSWORD_DEFAULT);

// Use username as email (patients have no real email)
$email = $username . '@patient.cognitiveai.local';

// ── Insert account — use OUTPUT INSERTED to get ID atomically ─
// SCOPE_IDENTITY() in a separate query is unreliable with sqlsrv;
// OUTPUT INSERTED returns the new ID within the same statement.
$insAcc = sqlsrv_query($conn,
    "INSERT INTO ACCOUNTS (USERNAME, EMAIL, PASSWORD, ROLE, DATE_CREATED, SUBSCRIPTION)
     OUTPUT INSERTED.ACCOUNT_ID
     VALUES (?, ?, ?, 'patient', GETDATE(), 'none')",
    [$username, $email, $hashed]
);

if (!$insAcc) {
    $err = sqlsrv_errors();
    echo json_encode(['success' => false, 'error' => 'Failed to create account: ' . ($err[0]['message'] ?? 'Unknown')]);
    exit();
}

$idRow    = sqlsrv_fetch_array($insAcc, SQLSRV_FETCH_ASSOC);
$newAccId = (int)($idRow['ACCOUNT_ID'] ?? 0);

if ($newAccId === 0) {
    echo json_encode(['success' => false, 'error' => 'Could not retrieve new account ID.']);
    exit();
}

// ── Insert patient record ─────────────────────────────────
$insPat = sqlsrv_query($conn,
    "INSERT INTO PATIENTS (ACCOUNT_ID, PRACTITIONER_ID, FULL_NAME)
     OUTPUT INSERTED.PATIENT_ID
     VALUES (?, ?, ?)",
    [$newAccId, $practitionerId, $fullName]
);

if (!$insPat) {
    $err = sqlsrv_errors();
    echo json_encode(['success' => false, 'error' => 'Failed to create patient record: ' . ($err[0]['message'] ?? 'Unknown')]);
    exit();
}

$pidRow    = sqlsrv_fetch_array($insPat, SQLSRV_FETCH_ASSOC);
$patientId = (int)($pidRow['PATIENT_ID'] ?? 0);

echo json_encode([
    'success'    => true,
    'patient_id' => $patientId,
    'full_name'  => $fullName,
    'username'   => $username,
    'password'   => $tempPass,
    'login_page' => 'services.php',
]);
