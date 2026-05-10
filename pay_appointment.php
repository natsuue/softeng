<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['Role']) || $_SESSION['Role'] !== 'patient') {
    header("Location: services.php");
    exit();
}
require_once 'config.php';

$accountId = $_SESSION['AccountID'];

// Resolve patient record
$patStmt = sqlsrv_query($conn,
    "SELECT PATIENT_ID FROM PATIENTS WHERE ACCOUNT_ID = ?", [$accountId]
);
$pat = sqlsrv_fetch_array($patStmt, SQLSRV_FETCH_ASSOC);
if (!$pat) { header("Location: patient_portal.php"); exit(); }
$patientId = $pat['PATIENT_ID'];

// Load and validate the appointment
$apptId = (int)($_GET['id'] ?? 0);
$apptStmt = sqlsrv_query($conn,
    "SELECT A.APPOINTMENT_ID, A.APPOINTMENT_DATE, A.FEE, A.STATUS, A.NOTES,
            AC.USERNAME AS PRACTITIONER_NAME
     FROM APPOINTMENTS A
     JOIN ACCOUNTS AC ON AC.ACCOUNT_ID = A.PRACTITIONER_ID
     WHERE A.APPOINTMENT_ID = ? AND A.PATIENT_ID = ? AND A.STATUS = 'pending'",
    [$apptId, $patientId]
);
$appt = sqlsrv_fetch_array($apptStmt, SQLSRV_FETCH_ASSOC);

// Check not already paid
if ($appt) {
    $paidCheck = sqlsrv_query($conn,
        "SELECT 1 FROM PAYMENTS WHERE APPOINTMENT_ID = ? AND STATUS = 'paid'",
        [$apptId]
    );
    if (sqlsrv_has_rows($paidCheck)) $appt = null;
}

if (!$appt) {
    header("Location: patient_portal.php");
    exit();
}

$error = '';

// ── Process payment ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $method  = trim($_POST['payment_method'] ?? '');
    $ref     = trim($_POST['reference_number'] ?? '');
    $allowed = ['gcash', 'maya', 'credit_card', 'cash'];

    if (!in_array($method, $allowed)) {
        $error = 'Please select a valid payment method.';
    } elseif (in_array($method, ['gcash', 'maya', 'credit_card']) && empty($ref)) {
        $error = 'Reference number is required for ' . ucfirst($method) . '.';
    } else {
        $ins = sqlsrv_query($conn,
            "INSERT INTO PAYMENTS (APPOINTMENT_ID, AMOUNT, PAYMENT_METHOD, REFERENCE_NUMBER, STATUS, PAID_AT)
             VALUES (?, ?, ?, ?, 'paid', GETDATE())",
            [$apptId, (float)$appt['FEE'], $method, $ref ?: null]
        );
        if ($ins) {
            // Confirm the appointment
            sqlsrv_query($conn,
                "UPDATE APPOINTMENTS SET STATUS = 'confirmed' WHERE APPOINTMENT_ID = ?",
                [$apptId]
            );
            header("Location: patient_portal.php?paid=1");
            exit();
        } else {
            $err = sqlsrv_errors();
            $error = 'Payment processing failed: ' . ($err[0]['message'] ?? 'Unknown error');
        }
    }
}

$pageTitle = 'Pay Appointment | CognitiveAI';
$pageStyle = '
body { background: #f0f4f8; }
.pay-wrap {
    max-width: 480px; margin: 60px auto; padding: 0 16px;
}
.pay-card {
    background: #fff; border-radius: 16px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.09); overflow: hidden;
}
.pay-header {
    background: linear-gradient(135deg, #0d1b2a 0%, #1a3a5c 100%);
    padding: 24px 28px; color: #fff;
}
.pay-header h5 { font-size: 1.15rem; font-weight: 700; margin: 0 0 3px; }
.pay-header small { color: rgba(255,255,255,.6); font-size: .83rem; }
.pay-body { padding: 28px; }
.amount-box {
    background: #f0f9ff; border: 1px solid #bae6fd;
    border-radius: 10px; padding: 16px 20px; text-align: center; margin-bottom: 24px;
}
.amount-box .amount { font-size: 2rem; font-weight: 800; color: #0c4a6e; }
.amount-box .label  { font-size: .8rem; color: #0369a1; margin-top: 2px; }

.method-grid {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 10px; margin-bottom: 20px;
}
.method-btn {
    border: 2px solid #e5e7eb; border-radius: 10px;
    padding: 12px; text-align: center; cursor: pointer;
    transition: border-color .15s, background .15s;
    font-size: .85rem; font-weight: 600; color: #374151;
    background: #fff;
}
.method-btn:hover { border-color: #1a3a5c; background: #f8faff; }
.method-btn.selected { border-color: #1a3a5c; background: #eff6ff; color: #1a3a5c; }
.method-btn i { display: block; font-size: 1.4rem; margin-bottom: 5px; }
.method-btn.gcash i    { color: #0147FF; }
.method-btn.maya i     { color: #00c07f; }
.method-btn.card i     { color: #7c3aed; }
.method-btn.cash i     { color: #059669; }

.form-label { font-size: .83rem; font-weight: 600; color: #374151; margin-bottom: 5px; }
.form-control {
    border: 1.5px solid #d1d5db; border-radius: 8px;
    padding: 10px 14px; font-size: .88rem;
    transition: border-color .18s, box-shadow .18s;
}
.form-control:focus {
    border-color: #1a3a5c;
    box-shadow: 0 0 0 3px rgba(26,58,92,0.09);
    outline: none;
}
.btn-pay {
    width: 100%; padding: 12px;
    background: #1a3a5c; color: #fff;
    border: none; border-radius: 8px;
    font-size: .95rem; font-weight: 700;
    cursor: pointer; transition: background .18s;
    margin-top: 8px;
}
.btn-pay:hover { background: #0d2440; }
.btn-pay:disabled { background: #9ca3af; cursor: not-allowed; }
';
require_once 'header.php';
?>

<div class="pay-wrap">
    <div class="pay-card">

        <div class="pay-header">
            <h5><i class="fas fa-lock me-2"></i>Secure Payment</h5>
            <small>
                Dr. <?= htmlspecialchars($appt['PRACTITIONER_NAME']) ?> &nbsp;·&nbsp;
                <?= fmt_date($appt['APPOINTMENT_DATE'], 'F d, Y \a\t g:i A') ?>
            </small>
        </div>

        <div class="pay-body">

            <?php if ($error): ?>
            <div class="alert alert-danger py-2 mb-4" style="font-size:.85rem;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="amount-box">
                <div class="amount">₱<?= number_format((float)$appt['FEE'], 2) ?></div>
                <div class="label">Consultation fee</div>
            </div>

            <?php if (!empty($appt['NOTES'])): ?>
            <div class="mb-4 p-3 bg-light rounded small">
                <i class="fas fa-notes-medical me-1 text-muted"></i>
                <?= htmlspecialchars($appt['NOTES']) ?>
            </div>
            <?php endif; ?>

            <form method="POST" id="payForm">
                <input type="hidden" name="payment_method" id="methodInput" value="">

                <label class="form-label mb-2">Select Payment Method</label>
                <div class="method-grid mb-3">
                    <div class="method-btn gcash" onclick="selectMethod('gcash', this)">
                        <i class="fas fa-mobile-alt"></i>GCash
                    </div>
                    <div class="method-btn maya" onclick="selectMethod('maya', this)">
                        <i class="fas fa-wallet"></i>Maya
                    </div>
                    <div class="method-btn card" onclick="selectMethod('credit_card', this)">
                        <i class="fas fa-credit-card"></i>Credit / Debit
                    </div>
                    <div class="method-btn cash" onclick="selectMethod('cash', this)">
                        <i class="fas fa-money-bill-wave"></i>Cash
                    </div>
                </div>

                <div id="refWrap" class="mb-4" style="display:none;">
                    <label class="form-label" id="refLabel">Reference Number</label>
                    <input type="text" name="reference_number" id="refInput" class="form-control"
                           placeholder="Enter reference / transaction number">
                    <div class="form-text mt-1" style="font-size:.78rem;" id="refHint"></div>
                </div>

                <div id="cashNote" class="mb-4 p-3 bg-light rounded small text-muted" style="display:none;">
                    <i class="fas fa-info-circle me-1"></i>
                    Cash payment will be confirmed by your clinician at the appointment.
                </div>

                <button type="submit" class="btn-pay" id="btnPay" disabled>
                    <i class="fas fa-lock me-2"></i>Confirm Payment
                </button>
            </form>

            <div class="text-center mt-3">
                <a href="patient_portal.php" class="text-muted small">
                    <i class="fas fa-arrow-left me-1"></i>Back to portal
                </a>
            </div>

        </div><!-- /pay-body -->
    </div><!-- /pay-card -->
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function selectMethod(value, el) {
    document.querySelectorAll('.method-btn').forEach(b => b.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('methodInput').value = value;
    document.getElementById('btnPay').disabled = false;

    const refWrap  = document.getElementById('refWrap');
    const cashNote = document.getElementById('cashNote');
    const refLabel = document.getElementById('refLabel');
    const refHint  = document.getElementById('refHint');

    if (value === 'cash') {
        refWrap.style.display  = 'none';
        cashNote.style.display = 'block';
    } else {
        cashNote.style.display = 'none';
        refWrap.style.display  = 'block';
        if (value === 'gcash') {
            refLabel.textContent = 'GCash Reference Number';
            refHint.textContent  = 'Found in your GCash transaction history (e.g. 1234567890)';
        } else if (value === 'maya') {
            refLabel.textContent = 'Maya Reference Number';
            refHint.textContent  = 'Found in your Maya app under transaction details';
        } else {
            refLabel.textContent = 'Card / Transaction Reference';
            refHint.textContent  = 'Found on your bank SMS / app notification';
        }
    }
}
</script>
</body>
</html>
