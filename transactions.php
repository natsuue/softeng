<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['Role'])) {
    header("Location: services.php");
    exit();
}
require_once 'config.php';

$role      = $_SESSION['Role'];
$accountId = $_SESSION['AccountID'];

// ── Prices for subscription plans ────────────────────────
$planPrice = ['basic' => 299, 'premium' => 599];

// ================================================================
// PRACTITIONER VIEW
// ================================================================
if ($role === 'practitioner') {

    // Subscription history
    $subStmt = sqlsrv_query($conn,
        "SELECT SUBSCRIPTION_ID, PLAN_TYPE, START_DATE, END_DATE, STATUS, MONTHLY_LIMIT
         FROM SUBSCRIPTIONS WHERE ACCOUNT_ID = ? ORDER BY START_DATE DESC",
        [$accountId]
    );
    $subs = [];
    while ($r = sqlsrv_fetch_array($subStmt, SQLSRV_FETCH_ASSOC)) $subs[] = $r;

    // Appointment payments received
    $apptStmt = sqlsrv_query($conn,
        "SELECT PAY.PAYMENT_ID, PAY.AMOUNT, PAY.PAYMENT_METHOD, PAY.REFERENCE_NUMBER, PAY.PAID_AT,
                P.FULL_NAME AS PATIENT_NAME,
                A.APPOINTMENT_DATE
         FROM PAYMENTS PAY
         JOIN APPOINTMENTS A ON A.APPOINTMENT_ID = PAY.APPOINTMENT_ID
         JOIN PATIENTS P ON P.PATIENT_ID = A.PATIENT_ID
         WHERE A.PRACTITIONER_ID = ? AND PAY.STATUS = 'paid'
         ORDER BY PAY.PAID_AT DESC",
        [$accountId]
    );
    $apptPayments = [];
    while ($r = sqlsrv_fetch_array($apptStmt, SQLSRV_FETCH_ASSOC)) $apptPayments[] = $r;

    // Stats
    $totalSubSpend   = array_sum(array_map(fn($s) => $s['STATUS'] !== 'cancelled' ? ($planPrice[$s['PLAN_TYPE']] ?? 0) : 0, $subs));
    $totalApptIncome = array_sum(array_column($apptPayments, 'AMOUNT'));
    $activeSub       = null;
    foreach ($subs as $s) { if ($s['STATUS'] === 'active') { $activeSub = $s; break; } }

// ================================================================
// PATIENT VIEW
// ================================================================
} elseif ($role === 'patient') {

    $patStmt = sqlsrv_query($conn, "SELECT PATIENT_ID FROM PATIENTS WHERE ACCOUNT_ID = ?", [$accountId]);
    $pat     = sqlsrv_fetch_array($patStmt, SQLSRV_FETCH_ASSOC);
    $patientId = $pat ? $pat['PATIENT_ID'] : null;

    $apptPayments = [];
    $totalPaid    = 0;
    if ($patientId) {
        $pStmt = sqlsrv_query($conn,
            "SELECT PAY.PAYMENT_ID, PAY.AMOUNT, PAY.PAYMENT_METHOD, PAY.REFERENCE_NUMBER, PAY.PAID_AT,
                    A.APPOINTMENT_DATE, A.NOTES,
                    AC.USERNAME AS PRACTITIONER_NAME
             FROM PAYMENTS PAY
             JOIN APPOINTMENTS A ON A.APPOINTMENT_ID = PAY.APPOINTMENT_ID
             JOIN ACCOUNTS AC ON AC.ACCOUNT_ID = A.PRACTITIONER_ID
             WHERE A.PATIENT_ID = ? AND PAY.STATUS = 'paid'
             ORDER BY PAY.PAID_AT DESC",
            [$patientId]
        );
        while ($r = sqlsrv_fetch_array($pStmt, SQLSRV_FETCH_ASSOC)) $apptPayments[] = $r;
        $totalPaid = array_sum(array_column($apptPayments, 'AMOUNT'));
    }

// ================================================================
// ADMIN VIEW
// ================================================================
} elseif ($role === 'admin') {

    // All subscriptions
    $subStmt = sqlsrv_query($conn,
        "SELECT S.SUBSCRIPTION_ID, S.PLAN_TYPE, S.START_DATE, S.END_DATE, S.STATUS,
                A.USERNAME, A.EMAIL
         FROM SUBSCRIPTIONS S
         JOIN ACCOUNTS A ON A.ACCOUNT_ID = S.ACCOUNT_ID
         ORDER BY S.START_DATE DESC"
    );
    $subs = [];
    while ($r = sqlsrv_fetch_array($subStmt, SQLSRV_FETCH_ASSOC)) $subs[] = $r;

    // All appointment payments
    $apptStmt = sqlsrv_query($conn,
        "SELECT PAY.PAYMENT_ID, PAY.AMOUNT, PAY.PAYMENT_METHOD, PAY.REFERENCE_NUMBER, PAY.PAID_AT,
                P.FULL_NAME AS PATIENT_NAME,
                AC_PRAC.USERNAME AS PRACTITIONER_NAME,
                A.APPOINTMENT_DATE
         FROM PAYMENTS PAY
         JOIN APPOINTMENTS A ON A.APPOINTMENT_ID = PAY.APPOINTMENT_ID
         JOIN PATIENTS P ON P.PATIENT_ID = A.PATIENT_ID
         JOIN ACCOUNTS AC_PRAC ON AC_PRAC.ACCOUNT_ID = A.PRACTITIONER_ID
         WHERE PAY.STATUS = 'paid'
         ORDER BY PAY.PAID_AT DESC"
    );
    $apptPayments = [];
    while ($r = sqlsrv_fetch_array($apptStmt, SQLSRV_FETCH_ASSOC)) $apptPayments[] = $r;

    // Stats
    $totalSubRevenue  = array_sum(array_map(fn($s) => $s['STATUS'] !== 'cancelled' ? ($planPrice[$s['PLAN_TYPE']] ?? 0) : 0, $subs));
    $totalApptRevenue = array_sum(array_column($apptPayments, 'AMOUNT'));
    $activeSubCount   = count(array_filter($subs, fn($s) => $s['STATUS'] === 'active'));

    // Filter
    $filterStatus = $_GET['status'] ?? 'all';

} else {
    header("Location: services.php");
    exit();
}

$pageTitle = 'Transactions | CognitiveAI';
$pageStyle = '
.welcome-banner {
    background: linear-gradient(135deg, #0d1b2a 0%, #1a3a5c 100%);
    border-radius: 14px; padding: 24px 28px; color: #fff; margin-bottom: 1.5rem;
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;
}
.welcome-banner h4 { font-size: 1.25rem; font-weight: 700; margin: 0 0 3px; }
.welcome-banner small { color: rgba(255,255,255,.6); font-size: .84rem; }
.tx-card {
    background: #fff; border-radius: 12px;
    border: 1px solid #e9ecef; padding: 20px 22px; margin-bottom: 1.5rem;
}
.tx-card h6 { font-weight: 700; font-size: .92rem; color: #1a1a2e; margin-bottom: 16px; }
.tx-badge-sub  { background: #ede9fe; color: #5b21b6; font-size: .72rem; }
.tx-badge-appt { background: #d1fae5; color: #065f46; font-size: .72rem; }
.method-icon { width: 28px; height: 28px; border-radius: 6px; display: inline-flex;
    align-items: center; justify-content: center; font-size: .8rem; margin-right: 6px; }
.method-gcash       { background: #dbeafe; color: #1d4ed8; }
.method-maya        { background: #d1fae5; color: #065f46; }
.method-credit_card { background: #f3e8ff; color: #6d28d9; }
.method-cash        { background: #fef9c3; color: #854d0e; }
';
require_once 'header.php';

function methodIcon(string $m): string {
    $map = ['gcash' => ['fa-mobile-alt','gcash'], 'maya' => ['fa-wallet','maya'],
            'credit_card' => ['fa-credit-card','credit_card'], 'cash' => ['fa-money-bill-wave','cash']];
    [$icon, $cls] = $map[$m] ?? ['fa-money-bill','cash'];
    return "<span class=\"method-icon method-$cls\"><i class=\"fas $icon\"></i></span>" . ucwords(str_replace('_', ' ', $m));
}
?>

<div class="container py-4">

    <!-- Banner -->
    <div class="welcome-banner">
        <div>
            <h4><i class="fas fa-receipt me-2"></i>Transactions</h4>
            <small>
                <?php if ($role === 'practitioner'): ?>Subscription billing &amp; appointment payments received
                <?php elseif ($role === 'patient'): ?>Your appointment payment history
                <?php else: ?>System-wide financial overview
                <?php endif; ?>
            </small>
        </div>
        <div class="text-end">
            <div style="font-size:.8rem; color:rgba(255,255,255,.5);"><?= date('F d, Y') ?></div>
        </div>
    </div>

<?php /* ============================================================
       PRACTITIONER
       ============================================================ */
if ($role === 'practitioner'): ?>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-primary"><i class="fas fa-credit-card"></i></div>
                <div class="stat-value"><?= $activeSub ? ucfirst($activeSub['PLAN_TYPE']) : 'Free' ?></div>
                <div class="stat-label">Current Plan</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-warning"><i class="fas fa-peso-sign"></i></div>
                <div class="stat-value">₱<?= number_format($totalSubSpend) ?></div>
                <div class="stat-label">Total Subscriptions</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-success"><i class="fas fa-hand-holding-usd"></i></div>
                <div class="stat-value">₱<?= number_format($totalApptIncome) ?></div>
                <div class="stat-label">Appointment Income</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-info"><i class="fas fa-calendar-check"></i></div>
                <div class="stat-value"><?= count($apptPayments) ?></div>
                <div class="stat-label">Paid Appointments</div>
            </div>
        </div>
    </div>

    <!-- Subscription history -->
    <div class="tx-card">
        <h6><i class="fas fa-credit-card me-2 text-primary"></i>Subscription Billing History</h6>
        <?php if (empty($subs)): ?>
        <div class="text-center text-muted py-4">No subscriptions yet. <a href="subscription.php">View plans</a></div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table cog-table align-middle">
                <thead><tr><th>#</th><th>Plan</th><th>Start</th><th>Expires</th><th>Amount</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($subs as $s): ?>
                <tr>
                    <td class="small text-muted"><?= $s['SUBSCRIPTION_ID'] ?></td>
                    <td>
                        <span class="badge <?= $s['PLAN_TYPE'] === 'premium' ? 'bg-warning text-dark' : 'bg-primary' ?>">
                            <?= ucfirst($s['PLAN_TYPE']) ?>
                        </span>
                    </td>
                    <td class="small"><?= fmt_date($s['START_DATE'], 'M d, Y') ?></td>
                    <td class="small"><?= fmt_date($s['END_DATE'], 'M d, Y') ?></td>
                    <td class="fw-semibold small">₱<?= number_format($planPrice[$s['PLAN_TYPE']] ?? 0) ?></td>
                    <td>
                        <span class="badge bg-<?= $s['STATUS'] === 'active' ? 'success' : ($s['STATUS'] === 'cancelled' ? 'danger' : 'secondary') ?>">
                            <?= $s['STATUS'] ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Appointment payments received -->
    <div class="tx-card">
        <h6><i class="fas fa-hand-holding-usd me-2 text-success"></i>Appointment Payments Received</h6>
        <?php if (empty($apptPayments)): ?>
        <div class="text-center text-muted py-4">No payments received yet.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table cog-table align-middle">
                <thead><tr><th>Patient</th><th>Appointment</th><th>Method</th><th>Reference</th><th>Amount</th><th>Paid On</th></tr></thead>
                <tbody>
                <?php foreach ($apptPayments as $p): ?>
                <tr>
                    <td class="fw-semibold small"><?= htmlspecialchars($p['PATIENT_NAME']) ?></td>
                    <td class="small"><?= fmt_date($p['APPOINTMENT_DATE'], 'M d, Y g:i A') ?></td>
                    <td class="small"><?= methodIcon($p['PAYMENT_METHOD']) ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($p['REFERENCE_NUMBER'] ?? '—') ?></td>
                    <td class="fw-semibold small text-success">₱<?= number_format((float)$p['AMOUNT'], 2) ?></td>
                    <td class="small"><?= fmt_date($p['PAID_AT'], 'M d, Y g:i A') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

<?php /* ============================================================
       PATIENT
       ============================================================ */
elseif ($role === 'patient'): ?>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
            <div class="stat-card">
                <div class="stat-icon text-primary"><i class="fas fa-receipt"></i></div>
                <div class="stat-value"><?= count($apptPayments) ?></div>
                <div class="stat-label">Total Payments</div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="stat-card">
                <div class="stat-icon text-success"><i class="fas fa-peso-sign"></i></div>
                <div class="stat-value">₱<?= number_format($totalPaid, 2) ?></div>
                <div class="stat-label">Total Paid</div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="stat-card">
                <div class="stat-icon text-info"><i class="fas fa-calendar-check"></i></div>
                <div class="stat-value"><?= count($apptPayments) > 0 ? fmt_date($apptPayments[0]['PAID_AT'], 'M d') : '—' ?></div>
                <div class="stat-label">Last Payment</div>
            </div>
        </div>
    </div>

    <div class="tx-card">
        <h6><i class="fas fa-history me-2 text-primary"></i>Payment History</h6>
        <?php if (empty($apptPayments)): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-receipt fa-3x mb-3 opacity-25"></i>
            <p>No payments recorded yet.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table cog-table align-middle">
                <thead><tr><th>Clinician</th><th>Appointment</th><th>Method</th><th>Reference</th><th>Amount</th><th>Paid On</th></tr></thead>
                <tbody>
                <?php foreach ($apptPayments as $p): ?>
                <tr>
                    <td class="fw-semibold small">Dr. <?= htmlspecialchars($p['PRACTITIONER_NAME']) ?></td>
                    <td class="small"><?= fmt_date($p['APPOINTMENT_DATE'], 'M d, Y g:i A') ?></td>
                    <td class="small"><?= methodIcon($p['PAYMENT_METHOD']) ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($p['REFERENCE_NUMBER'] ?? '—') ?></td>
                    <td class="fw-semibold small text-success">₱<?= number_format((float)$p['AMOUNT'], 2) ?></td>
                    <td class="small"><?= fmt_date($p['PAID_AT'], 'M d, Y') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

<?php /* ============================================================
       ADMIN
       ============================================================ */
elseif ($role === 'admin'): ?>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-primary"><i class="fas fa-credit-card"></i></div>
                <div class="stat-value"><?= $activeSubCount ?></div>
                <div class="stat-label">Active Plans</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-warning"><i class="fas fa-peso-sign"></i></div>
                <div class="stat-value">₱<?= number_format($totalSubRevenue) ?></div>
                <div class="stat-label">Subscription Revenue</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-success"><i class="fas fa-hand-holding-usd"></i></div>
                <div class="stat-value">₱<?= number_format($totalApptRevenue) ?></div>
                <div class="stat-label">Appointment Revenue</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-info"><i class="fas fa-coins"></i></div>
                <div class="stat-value">₱<?= number_format($totalSubRevenue + $totalApptRevenue) ?></div>
                <div class="stat-label">Total Revenue</div>
            </div>
        </div>
    </div>

    <!-- Subscriptions -->
    <div class="tx-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0"><i class="fas fa-credit-card me-2 text-primary"></i>All Subscription Transactions</h6>
            <div class="d-flex gap-2">
                <a href="?status=all"       class="btn btn-sm <?= $filterStatus==='all'       ? 'btn-dark' : 'btn-outline-secondary' ?>">All</a>
                <a href="?status=active"    class="btn btn-sm <?= $filterStatus==='active'    ? 'btn-dark' : 'btn-outline-secondary' ?>">Active</a>
                <a href="?status=cancelled" class="btn btn-sm <?= $filterStatus==='cancelled' ? 'btn-dark' : 'btn-outline-secondary' ?>">Cancelled</a>
            </div>
        </div>
        <?php
        $filteredSubs = $filterStatus === 'all' ? $subs
            : array_filter($subs, fn($s) => $s['STATUS'] === $filterStatus);
        ?>
        <?php if (empty($filteredSubs)): ?>
        <div class="text-center text-muted py-4">No subscriptions found.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table cog-table align-middle">
                <thead><tr><th>Practitioner</th><th>Plan</th><th>Start</th><th>Expires</th><th>Amount</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($filteredSubs as $s): ?>
                <tr>
                    <td>
                        <div class="fw-semibold small"><?= htmlspecialchars($s['USERNAME']) ?></div>
                        <div class="text-muted" style="font-size:.74rem;"><?= htmlspecialchars($s['EMAIL']) ?></div>
                    </td>
                    <td><span class="badge <?= $s['PLAN_TYPE']==='premium' ? 'bg-warning text-dark' : 'bg-primary' ?>"><?= ucfirst($s['PLAN_TYPE']) ?></span></td>
                    <td class="small"><?= fmt_date($s['START_DATE'], 'M d, Y') ?></td>
                    <td class="small"><?= fmt_date($s['END_DATE'], 'M d, Y') ?></td>
                    <td class="fw-semibold small">₱<?= number_format($planPrice[$s['PLAN_TYPE']] ?? 0) ?></td>
                    <td><span class="badge bg-<?= $s['STATUS']==='active' ? 'success' : ($s['STATUS']==='cancelled' ? 'danger' : 'secondary') ?>"><?= $s['STATUS'] ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Appointment payments -->
    <div class="tx-card">
        <h6><i class="fas fa-calendar-check me-2 text-success"></i>All Appointment Payments</h6>
        <?php if (empty($apptPayments)): ?>
        <div class="text-center text-muted py-4">No appointment payments recorded yet.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table cog-table align-middle">
                <thead><tr><th>Patient</th><th>Practitioner</th><th>Appointment</th><th>Method</th><th>Reference</th><th>Amount</th><th>Paid On</th></tr></thead>
                <tbody>
                <?php foreach ($apptPayments as $p): ?>
                <tr>
                    <td class="small fw-semibold"><?= htmlspecialchars($p['PATIENT_NAME']) ?></td>
                    <td class="small"><?= htmlspecialchars($p['PRACTITIONER_NAME']) ?></td>
                    <td class="small"><?= fmt_date($p['APPOINTMENT_DATE'], 'M d, Y') ?></td>
                    <td class="small"><?= methodIcon($p['PAYMENT_METHOD']) ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($p['REFERENCE_NUMBER'] ?? '—') ?></td>
                    <td class="fw-semibold small text-success">₱<?= number_format((float)$p['AMOUNT'], 2) ?></td>
                    <td class="small"><?= fmt_date($p['PAID_AT'], 'M d, Y g:i A') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

<?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
