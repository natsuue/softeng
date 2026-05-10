<?php
// cancel_subscription.php — AJAX POST handler (no HTML).
// Marks the current practitioner's active subscription as cancelled.
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['Role']) || $_SESSION['Role'] !== 'practitioner') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

require_once 'config.php';

$accountId = $_SESSION['AccountID'];

$result = sqlsrv_query($conn,
    "UPDATE SUBSCRIPTIONS SET STATUS = 'cancelled'
     WHERE ACCOUNT_ID = ? AND STATUS = 'active'",
    [$accountId]
);

if ($result) {
    sqlsrv_query($conn,
        "UPDATE ACCOUNTS SET SUBSCRIPTION = 'free' WHERE ACCOUNT_ID = ?",
        [$accountId]
    );
    $_SESSION['Subscription'] = 'free';
    echo json_encode(['success' => true]);
} else {
    $err = sqlsrv_errors();
    echo json_encode(['success' => false, 'error' => $err[0]['message'] ?? 'Unknown error']);
}
