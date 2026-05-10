<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['Role']) || $_SESSION['Role'] !== 'practitioner') {
    header("Location: services.php");
    exit();
}
require_once 'config.php';

$accountId = $_SESSION['AccountID'];
$msg = $msgType = '';

// ── Create appointment ────────────────────────────────────
if (isset($_POST['create_appointment'])) {
    $patientId = (int)($_POST['patient_id'] ?? 0);
    $apptDateRaw = trim($_POST['appointment_date'] ?? '');
    $fee         = (float)($_POST['fee'] ?? 0);
    $notes       = trim($_POST['notes'] ?? '');

    // datetime-local sends "2026-05-09T14:30" — convert T→space for SQL Server
    $apptDate = $apptDateRaw ? str_replace('T', ' ', $apptDateRaw) . ':00' : '';

    // Verify patient belongs to this practitioner
    $check = sqlsrv_query($conn,
        "SELECT 1 FROM PATIENTS WHERE PATIENT_ID = ? AND PRACTITIONER_ID = ?",
        [$patientId, $accountId]
    );
    if (!sqlsrv_has_rows($check)) {
        $msg = 'Invalid patient selected.';
        $msgType = 'danger';
    } elseif (!$apptDate) {
        $msg = 'Appointment date is required.';
        $msgType = 'danger';
    } else {
        $ins = sqlsrv_query($conn,
            "INSERT INTO APPOINTMENTS (PATIENT_ID, PRACTITIONER_ID, APPOINTMENT_DATE, FEE, STATUS, NOTES)
             VALUES (?, ?, ?, ?, 'pending', ?)",
            [$patientId, $accountId, $apptDate, $fee, $notes ?: null]
        );
        if ($ins) {
            $msg = 'Appointment scheduled successfully.';
            $msgType = 'success';
        } else {
            $err = sqlsrv_errors();
            $msg = 'Failed to schedule: ' . ($err[0]['message'] ?? 'Unknown error');
            $msgType = 'danger';
        }
    }
}

// ── Cancel appointment ────────────────────────────────────
if (isset($_POST['cancel_appointment'])) {
    $apptId = (int)($_POST['appt_id'] ?? 0);
    $r = sqlsrv_query($conn,
        "UPDATE APPOINTMENTS SET STATUS = 'cancelled'
         WHERE APPOINTMENT_ID = ? AND PRACTITIONER_ID = ? AND STATUS = 'pending'",
        [$apptId, $accountId]
    );
    $msg = sqlsrv_rows_affected($r) > 0 ? 'Appointment cancelled.' : 'Could not cancel (already confirmed or paid).';
    $msgType = sqlsrv_rows_affected($r) > 0 ? 'success' : 'warning';
}

// ── Load patients for dropdown ────────────────────────────
$pStmt = sqlsrv_query($conn,
    "SELECT PATIENT_ID, FULL_NAME FROM PATIENTS WHERE PRACTITIONER_ID = ? ORDER BY FULL_NAME ASC",
    [$accountId]
);
$patients = [];
while ($r = sqlsrv_fetch_array($pStmt, SQLSRV_FETCH_ASSOC)) $patients[] = $r;

// ── Load appointments with payment info ───────────────────
$aStmt = sqlsrv_query($conn,
    "SELECT A.APPOINTMENT_ID, A.APPOINTMENT_DATE, A.FEE, A.STATUS, A.NOTES,
            P.FULL_NAME AS PATIENT_NAME,
            PAY.STATUS        AS PAY_STATUS,
            PAY.PAYMENT_METHOD,
            PAY.REFERENCE_NUMBER,
            PAY.PAID_AT
     FROM APPOINTMENTS A
     JOIN PATIENTS P ON P.PATIENT_ID = A.PATIENT_ID
     LEFT JOIN PAYMENTS PAY ON PAY.APPOINTMENT_ID = A.APPOINTMENT_ID AND PAY.STATUS = 'paid'
     WHERE A.PRACTITIONER_ID = ?
     ORDER BY A.APPOINTMENT_DATE DESC",
    [$accountId]
);
$appointments = [];
while ($r = sqlsrv_fetch_array($aStmt, SQLSRV_FETCH_ASSOC)) $appointments[] = $r;

// ── Stats ─────────────────────────────────────────────────
$totalAppts    = count($appointments);
$pendingCount  = count(array_filter($appointments, fn($a) => $a['STATUS'] === 'pending'));
$paidCount     = count(array_filter($appointments, fn($a) => $a['PAY_STATUS'] === 'paid'));
$totalRevenue  = array_sum(array_map(fn($a) => $a['PAY_STATUS'] === 'paid' ? (float)$a['FEE'] : 0, $appointments));

$pageTitle = 'Appointments | CognitiveAI';
$pageStyle = '
.welcome-banner {
    background: linear-gradient(135deg, #0d1b2a 0%, #1a3a5c 100%);
    border-radius: 14px; padding: 24px 28px; color: #fff; margin-bottom: 1.5rem;
}
.welcome-banner h4 { font-size: 1.25rem; font-weight: 700; margin: 0 0 3px; }
.welcome-banner small { color: rgba(255,255,255,.6); font-size: .84rem; }

.appt-card {
    background: #fff; border-radius: 12px; border: 1px solid #e9ecef;
    padding: 22px 24px;
}
.appt-card h6 { font-weight: 700; font-size: .95rem; color: #1a1a2e; margin-bottom: 18px; }

.pay-badge-paid      { background: #d1fae5; color: #065f46; }
.pay-badge-pending   { background: #fef3c7; color: #92400e; }
.pay-badge-cancelled { background: #fee2e2; color: #991b1b; }
';
require_once 'header.php';
?>

<div class="container py-4">

    <!-- Banner -->
    <div class="welcome-banner">
        <h4><i class="fas fa-calendar-alt me-2"></i>Appointments</h4>
        <small>Schedule patient consultations and track payment status</small>
    </div>

    <?php if ($msg): ?>
    <div class="alert alert-<?= $msgType ?> alert-dismissible fade show mb-4">
        <?= htmlspecialchars($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-primary"><i class="fas fa-calendar-check"></i></div>
                <div class="stat-value"><?= $totalAppts ?></div>
                <div class="stat-label">Total</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-warning"><i class="fas fa-clock"></i></div>
                <div class="stat-value"><?= $pendingCount ?></div>
                <div class="stat-label">Awaiting Payment</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-success"><i class="fas fa-check-circle"></i></div>
                <div class="stat-value"><?= $paidCount ?></div>
                <div class="stat-label">Paid</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-info"><i class="fas fa-peso-sign"></i></div>
                <div class="stat-value">₱<?= number_format($totalRevenue, 0) ?></div>
                <div class="stat-label">Revenue Collected</div>
            </div>
        </div>
    </div>

    <div class="row g-4">

        <!-- New Appointment Form -->
        <div class="col-lg-4">
            <div class="appt-card h-100">
                <h6><i class="fas fa-plus-circle me-2 text-primary"></i>Schedule Appointment</h6>

                <?php if (empty($patients)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-users fa-2x mb-2 opacity-25"></i>
                    <p class="small">No patients yet.<br>
                    <a href="product.php">Register a patient</a> first.</p>
                </div>
                <?php else: ?>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:.83rem;">Patient</label>
                        <select name="patient_id" class="form-select form-select-sm" required>
                            <option value="">— Select patient —</option>
                            <?php foreach ($patients as $p): ?>
                            <option value="<?= $p['PATIENT_ID'] ?>"><?= htmlspecialchars($p['FULL_NAME']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:.83rem;">Date &amp; Time</label>
                        <input type="datetime-local" name="appointment_date" class="form-control form-control-sm" required
                               min="<?= date('Y-m-d\TH:i') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:.83rem;">Consultation Fee (₱)</label>
                        <input type="number" name="fee" class="form-control form-control-sm"
                               min="0" step="0.01" placeholder="e.g. 500.00" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold" style="font-size:.83rem;">Notes <span class="text-muted fw-normal">(optional)</span></label>
                        <textarea name="notes" class="form-control form-control-sm" rows="2"
                                  placeholder="Reason for visit, instructions..."></textarea>
                    </div>

                    <button type="submit" name="create_appointment" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-calendar-plus me-1"></i>Schedule
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Appointment List -->
        <div class="col-lg-8">
            <div class="appt-card">
                <h6><i class="fas fa-list me-2 text-primary"></i>All Appointments</h6>

                <?php if (empty($appointments)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-calendar fa-3x mb-3 opacity-25"></i>
                    <p>No appointments scheduled yet.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table cog-table align-middle">
                        <thead>
                            <tr>
                                <th>Patient</th>
                                <th>Date &amp; Time</th>
                                <th>Fee</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($appointments as $a): ?>
                        <?php
                            $isPaid      = $a['PAY_STATUS'] === 'paid';
                            $isCancelled = $a['STATUS'] === 'cancelled';
                        ?>
                        <tr>
                            <td class="fw-semibold small"><?= htmlspecialchars($a['PATIENT_NAME']) ?></td>
                            <td class="small">
                                <?= fmt_date($a['APPOINTMENT_DATE'], 'M d, Y') ?><br>
                                <span class="text-muted"><?= fmt_date($a['APPOINTMENT_DATE'], 'g:i A') ?></span>
                            </td>
                            <td class="fw-semibold small">₱<?= number_format((float)$a['FEE'], 2) ?></td>
                            <td>
                                <?php if ($isPaid): ?>
                                <span class="badge pay-badge-paid px-2 py-1" style="font-size:.75rem;">
                                    <i class="fas fa-check me-1"></i>Paid
                                </span>
                                <div class="text-muted" style="font-size:.72rem; margin-top:2px;">
                                    <?= htmlspecialchars($a['PAYMENT_METHOD']) ?>
                                    <?= !empty($a['REFERENCE_NUMBER']) ? '· ' . htmlspecialchars($a['REFERENCE_NUMBER']) : '' ?>
                                </div>
                                <?php elseif ($isCancelled): ?>
                                <span class="badge pay-badge-cancelled px-2 py-1" style="font-size:.75rem;">Cancelled</span>
                                <?php else: ?>
                                <span class="badge pay-badge-pending px-2 py-1" style="font-size:.75rem;">
                                    <i class="fas fa-clock me-1"></i>Awaiting
                                </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?= $isCancelled ? 'secondary' : ($isPaid ? 'success' : 'warning text-dark') ?>"
                                      style="font-size:.72rem;">
                                    <?= $isCancelled ? 'Cancelled' : ($isPaid ? 'Confirmed' : 'Pending') ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!$isPaid && !$isCancelled): ?>
                                <form method="POST" onsubmit="return confirm('Cancel this appointment?')">
                                    <input type="hidden" name="appt_id" value="<?= $a['APPOINTMENT_ID'] ?>">
                                    <button type="submit" name="cancel_appointment"
                                            class="btn btn-outline-danger btn-sm" style="font-size:.75rem;">
                                        <i class="fas fa-times"></i> Cancel
                                    </button>
                                </form>
                                <?php else: ?>
                                <span class="text-muted" style="font-size:.75rem;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if (!empty($a['NOTES'])): ?>
                        <tr class="table-light">
                            <td colspan="6" class="small text-muted py-1 ps-3">
                                <i class="fas fa-sticky-note me-1"></i><?= htmlspecialchars($a['NOTES']) ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
