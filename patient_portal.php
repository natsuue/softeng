<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['Role']) || $_SESSION['Role'] !== 'patient') {
    header("Location: services.php");
    exit();
}
require_once 'config.php';

$accountId = $_SESSION['AccountID'];

// ── Get this patient's record ─────────────────────────────
$patStmt = sqlsrv_query($conn,
    "SELECT P.PATIENT_ID, P.FULL_NAME, P.DATE_ADDED, A2.USERNAME AS PRACTITIONER_NAME
     FROM PATIENTS P
     JOIN ACCOUNTS A2 ON A2.ACCOUNT_ID = P.PRACTITIONER_ID
     WHERE P.ACCOUNT_ID = ?",
    [$accountId]
);
$patient = sqlsrv_fetch_array($patStmt, SQLSRV_FETCH_ASSOC);

if (!$patient) {
    $pageTitle = 'My Results | CognitiveAI';
    require_once 'header.php';
    echo '<div class="container py-5"><div class="alert alert-warning">Patient record not found. Please contact your clinician.</div></div>';
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script></body></html>';
    exit();
}

$patientId = $patient['PATIENT_ID'];

// ── Load all assessments ──────────────────────────────────
$aStmt = sqlsrv_query($conn,
    "SELECT ASSESSMENT_ID, DATE_ASSESSED, AGE, GENDER, SLEEP_DURATION, STRESS_LEVEL,
            DIET_TYPE, DAILY_SCREEN_TIME, EXERCISE_FREQUENCY,
            CAFFEINE_INTAKE, REACTION_TIME, MEMORY_TEST_SCORE,
            COGNITIVE_SCORE, NOTES
     FROM ASSESSMENTS
     WHERE PATIENT_ID = ?
     ORDER BY DATE_ASSESSED DESC",
    [$patientId]
);
$assessments = [];
while ($r = sqlsrv_fetch_array($aStmt, SQLSRV_FETCH_ASSOC)) {
    $assessments[] = $r;
}

$totalTests  = count($assessments);
$avgScore    = $totalTests > 0 ? array_sum(array_column($assessments, 'COGNITIVE_SCORE')) / $totalTests : null;
$latestScore = $totalTests > 0 ? (float)$assessments[0]['COGNITIVE_SCORE'] : null;
$bestScore   = $totalTests > 0 ? max(array_column($assessments, 'COGNITIVE_SCORE')) : null;
$scoreDelta  = $totalTests >= 2 ? round($latestScore - (float)$assessments[1]['COGNITIVE_SCORE'], 1) : null;

// ── Chart data: chronological order ──────────────────────
$chartLabels = [];
$chartData   = [];
$chronological = array_reverse($assessments);
foreach ($chronological as $ca) {
    $chartLabels[] = fmt_date($ca['DATE_ASSESSED'], 'M d');
    $chartData[]   = round((float)$ca['COGNITIVE_SCORE'], 1);
}

// ── Greeting ──────────────────────────────────────────────
$hour     = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

function pBand(float $s): string      { return $s >= 75 ? 'high'    : ($s >= 50 ? 'mid'     : 'low'); }
function pBandLabel(float $s): string { return $s >= 75 ? 'High Performance' : ($s >= 50 ? 'Moderate Performance' : 'Needs Attention'); }
function pBadgeCss(float $s): string  { return $s >= 75 ? 'success' : ($s >= 50 ? 'warning' : 'danger'); }

$pageTitle = 'My Results | CognitiveAI';
$pageStyle = '
.welcome-banner {
    background: linear-gradient(135deg, #0d1b2a 0%, #1a3a5c 100%);
    border-radius: 14px; padding: 26px 30px; color: #fff;
    display: flex; align-items: center;
    justify-content: space-between; flex-wrap: wrap; gap: 16px;
}
.welcome-banner h4 { font-size: 1.35rem; font-weight: 700; margin: 0 0 4px; }
.welcome-banner small { color: rgba(255,255,255,.65); font-size: .85rem; }
.welcome-banner .info-pill {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(255,255,255,.12); border-radius: 20px;
    padding: 4px 12px; font-size: .78rem; color: rgba(255,255,255,.85);
    margin-left: 10px;
}
.score-banner-box {
    text-align: center;
    background: rgba(255,255,255,.08);
    border-radius: 12px;
    padding: 14px 22px;
    min-width: 130px;
}
.score-banner-box .sbb-val  { font-size: 2.4rem; font-weight: 800; line-height: 1; }
.score-banner-box .sbb-lbl  { font-size: .72rem; color: rgba(255,255,255,.6); margin-top: 4px; }
.score-banner-box .sbb-delta { font-size: .82rem; font-weight: 600; margin-top: 6px; }
.delta-up   { color: #69db7c; }
.delta-down { color: #ff6b6b; }
.delta-flat { color: rgba(255,255,255,.5); }
.chart-card {
    background: #fff; border-radius: 12px;
    border: 1px solid #e9ecef; padding: 20px 22px;
}
.chart-card h6 { font-weight: 700; font-size: .9rem; color: #1a1a2e; margin-bottom: 4px; }
.chart-card .chart-sub { font-size: .78rem; color: #adb5bd; margin-bottom: 16px; }
';
require_once 'header.php';
?>

<div class="container py-4">

    <!-- ── Welcome Banner ────────────────────────────────── -->
    <div class="welcome-banner mb-4">
        <div>
            <h4><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($patient['FULL_NAME']) ?></h4>
            <small>
                <i class="fas fa-user-md me-1"></i>Clinician: <strong><?= htmlspecialchars($patient['PRACTITIONER_NAME']) ?></strong>
                <span class="info-pill ms-2">
                    <i class="fas fa-calendar-plus"></i>
                    Since <?= fmt_date($patient['DATE_ADDED'], 'M d, Y') ?>
                </span>
            </small>
        </div>

        <a href="report.php" target="_blank"
           style="display:inline-flex; align-items:center; gap:7px;
                  background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.2);
                  border-radius:8px; padding:9px 16px; color:#fff; text-decoration:none;
                  font-size:.83rem; font-weight:600; transition:background .15s;"
           onmouseover="this.style.background='rgba(255,255,255,.2)'"
           onmouseout="this.style.background='rgba(255,255,255,.12)'">
            <i class="fas fa-file-medical"></i> My Report
        </a>

        <?php if ($latestScore !== null): ?>
        <div class="score-banner-box">
            <div class="sbb-val" style="color: <?= $latestScore >= 75 ? '#69db7c' : ($latestScore >= 50 ? '#ffd43b' : '#ff6b6b') ?>">
                <?= round($latestScore, 1) ?>
            </div>
            <div class="sbb-lbl">Latest Score</div>
            <?php if ($scoreDelta !== null): ?>
            <div class="sbb-delta <?= $scoreDelta > 0 ? 'delta-up' : ($scoreDelta < 0 ? 'delta-down' : 'delta-flat') ?>">
                <?= $scoreDelta > 0 ? '▲ +' : ($scoreDelta < 0 ? '▼ ' : '● ') ?><?= abs($scoreDelta) ?> vs prev
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['paid'])): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4">
        <i class="fas fa-check-circle me-2"></i>
        Payment confirmed! Your appointment is now scheduled.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ── Stat Cards ─────────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-primary"><i class="fas fa-clipboard-list"></i></div>
                <div class="stat-value"><?= $totalTests ?></div>
                <div class="stat-label">Total Tests</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-info"><i class="fas fa-chart-line"></i></div>
                <div class="stat-value"><?= $avgScore !== null ? round($avgScore, 1) : '—' ?></div>
                <div class="stat-label">Average Score</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-success"><i class="fas fa-trophy"></i></div>
                <div class="stat-value"><?= $bestScore !== null ? round($bestScore, 1) : '—' ?></div>
                <div class="stat-label">Best Score</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon <?= $latestScore !== null ? 'text-' . pBadgeCss($latestScore) : 'text-muted' ?>">
                    <i class="fas fa-brain"></i>
                </div>
                <div class="stat-value"><?= $latestScore !== null ? round($latestScore, 1) : '—' ?></div>
                <div class="stat-label">
                    Latest Score
                    <?php if ($scoreDelta !== null): ?>
                    <span class="ms-1 fw-bold <?= $scoreDelta > 0 ? 'text-success' : ($scoreDelta < 0 ? 'text-danger' : 'text-muted') ?>" style="font-size:.75rem;">
                        <?= $scoreDelta > 0 ? '▲' : ($scoreDelta < 0 ? '▼' : '●') ?> <?= abs($scoreDelta) ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Appointments ──────────────────────────────────── -->
    <?php
    $apptStmt = sqlsrv_query($conn,
        "SELECT A.APPOINTMENT_ID, A.APPOINTMENT_DATE, A.FEE, A.STATUS, A.NOTES,
                AC.USERNAME AS PRACTITIONER_NAME,
                PAY.STATUS AS PAY_STATUS
         FROM APPOINTMENTS A
         JOIN ACCOUNTS AC ON AC.ACCOUNT_ID = A.PRACTITIONER_ID
         LEFT JOIN PAYMENTS PAY ON PAY.APPOINTMENT_ID = A.APPOINTMENT_ID AND PAY.STATUS = 'paid'
         WHERE A.PATIENT_ID = ?
         ORDER BY A.APPOINTMENT_DATE DESC",
        [$patientId]
    );
    $appts = [];
    while ($ar = sqlsrv_fetch_array($apptStmt, SQLSRV_FETCH_ASSOC)) $appts[] = $ar;
    $unpaidCount = count(array_filter($appts, fn($x) => $x['PAY_STATUS'] !== 'paid' && $x['STATUS'] === 'pending'));
    ?>
    <?php if (!empty($appts)): ?>
    <div class="cog-card p-4 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0">
                <i class="fas fa-calendar-alt me-2 text-primary"></i>Appointments
            </h5>
            <?php if ($unpaidCount > 0): ?>
            <span class="badge bg-warning text-dark">
                <?= $unpaidCount ?> unpaid
            </span>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table cog-table align-middle mb-0">
                <thead>
                    <tr><th>Clinician</th><th>Date &amp; Time</th><th>Fee</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($appts as $ap): ?>
                <?php $paid = $ap['PAY_STATUS'] === 'paid'; $cancelled = $ap['STATUS'] === 'cancelled'; ?>
                <tr>
                    <td class="small fw-semibold">Dr. <?= htmlspecialchars($ap['PRACTITIONER_NAME']) ?></td>
                    <td class="small"><?= fmt_date($ap['APPOINTMENT_DATE'], 'M d, Y \a\t g:i A') ?></td>
                    <td class="small fw-semibold">₱<?= number_format((float)$ap['FEE'], 2) ?></td>
                    <td>
                        <?php if ($cancelled): ?>
                        <span class="badge bg-secondary" style="font-size:.75rem;">Cancelled</span>
                        <?php elseif ($paid): ?>
                        <span class="badge bg-success" style="font-size:.75rem;"><i class="fas fa-check me-1"></i>Paid</span>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark" style="font-size:.75rem;"><i class="fas fa-clock me-1"></i>Unpaid</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$paid && !$cancelled): ?>
                        <a href="pay_appointment.php?id=<?= $ap['APPOINTMENT_ID'] ?>"
                           class="btn btn-sm btn-primary" style="font-size:.78rem;">
                            <i class="fas fa-credit-card me-1"></i>Pay Now
                        </a>
                        <?php else: ?>
                        <span class="text-muted" style="font-size:.75rem;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Score Trend Chart ──────────────────────────────── -->
    <?php if ($totalTests >= 2): ?>
    <div class="chart-card mb-4">
        <h6><i class="fas fa-chart-line me-2 text-primary"></i>Score Trend</h6>
        <div class="chart-sub">Your cognitive score across all assessments</div>
        <canvas id="trendChart" height="90"></canvas>
    </div>
    <?php endif; ?>

    <!-- ── Assessment History ─────────────────────────────── -->
    <div class="cog-card p-4">
        <h5 class="fw-bold mb-4">Assessment History</h5>

        <?php if (empty($assessments)): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-brain fa-3x mb-3 opacity-25"></i>
            <p>No assessments recorded yet.<br>Your clinician will run your first cognitive test.</p>
        </div>

        <?php else: ?>
        <div class="timeline">
            <?php foreach ($assessments as $i => $a): ?>
            <?php $sc = (float)$a['COGNITIVE_SCORE']; ?>
            <div class="timeline-item mb-4 <?= $i > 0 ? 'border-top pt-4' : '' ?>">
                <div class="row align-items-start g-3">

                    <!-- Score Badge -->
                    <div class="col-md-2 text-center">
                        <div class="result-box py-3 px-2" style="background: <?= $sc >= 75 ? '#0a4d2e' : ($sc >= 50 ? '#4a3000' : '#4a0010') ?>;">
                            <div class="score-label" style="font-size:.65rem;">Score</div>
                            <div class="score-value" style="font-size:2.2rem;"><?= round($sc, 1) ?></div>
                        </div>
                        <span class="badge bg-<?= pBadgeCss($sc) ?> mt-2 w-100">
                            <?= pBandLabel($sc) ?>
                        </span>
                    </div>

                    <!-- Details -->
                    <div class="col-md-10">
                        <div class="d-flex justify-content-between mb-2">
                            <h6 class="fw-semibold mb-0">
                                Assessment #<?= $totalTests - $i ?>
                            </h6>
                            <small class="text-muted">
                                <i class="fas fa-calendar-alt me-1"></i>
                                <?= fmt_date($a['DATE_ASSESSED'], 'F d, Y \a\t g:i A') ?>
                            </small>
                        </div>

                        <div class="row g-2">
                            <div class="col-6 col-md-4">
                                <div class="small text-muted">Age / Gender</div>
                                <div class="fw-semibold small"><?= $a['AGE'] ?> / <?= $a['GENDER'] ?></div>
                            </div>
                            <div class="col-6 col-md-4">
                                <div class="small text-muted">Sleep</div>
                                <div class="fw-semibold small"><?= $a['SLEEP_DURATION'] ?> hrs/night</div>
                            </div>
                            <div class="col-6 col-md-4">
                                <div class="small text-muted">Stress Level</div>
                                <div class="fw-semibold small"><?= $a['STRESS_LEVEL'] ?>/10</div>
                            </div>
                            <div class="col-6 col-md-4">
                                <div class="small text-muted">Diet</div>
                                <div class="fw-semibold small"><?= $a['DIET_TYPE'] ?></div>
                            </div>
                            <div class="col-6 col-md-4">
                                <div class="small text-muted">Exercise</div>
                                <div class="fw-semibold small"><?= $a['EXERCISE_FREQUENCY'] ?></div>
                            </div>
                            <div class="col-6 col-md-4">
                                <div class="small text-muted">Reaction Time</div>
                                <div class="fw-semibold small"><?= $a['REACTION_TIME'] ?> ms</div>
                            </div>
                            <div class="col-6 col-md-4">
                                <div class="small text-muted">Caffeine</div>
                                <div class="fw-semibold small"><?= $a['CAFFEINE_INTAKE'] ?> cups/day</div>
                            </div>
                            <div class="col-6 col-md-4">
                                <div class="small text-muted">Screen Time</div>
                                <div class="fw-semibold small"><?= $a['DAILY_SCREEN_TIME'] ?> hrs/day</div>
                            </div>
                            <div class="col-6 col-md-4">
                                <div class="small text-muted">Memory Score</div>
                                <div class="fw-semibold small"><?= $a['MEMORY_TEST_SCORE'] ?></div>
                            </div>
                        </div>

                        <?php if (!empty($a['NOTES'])): ?>
                        <div class="mt-2 p-2 bg-light rounded small">
                            <strong>Clinician Notes:</strong> <?= htmlspecialchars($a['NOTES']) ?>
                        </div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <p class="text-center text-muted small mt-4">
        <i class="fas fa-lock me-1"></i>
        Your data is private and only visible to you and your assigned clinician.
    </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($totalTests >= 2): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function() {
    const labels = <?= json_encode($chartLabels) ?>;
    const data   = <?= json_encode($chartData) ?>;

    const ctx = document.getElementById('trendChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: 'Cognitive Score',
                data,
                borderColor: '#1a3a5c',
                backgroundColor: 'rgba(26,58,92,0.08)',
                borderWidth: 2.5,
                pointBackgroundColor: data.map(v => v >= 75 ? '#198754' : (v >= 50 ? '#fd7e14' : '#dc3545')),
                pointRadius: 5,
                pointHoverRadius: 7,
                fill: true,
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ' Score: ' + ctx.parsed.y,
                        afterLabel: ctx => {
                            const v = ctx.parsed.y;
                            return v >= 75 ? 'High Performance' : (v >= 50 ? 'Moderate' : 'Needs Attention');
                        }
                    }
                }
            },
            scales: {
                y: {
                    min: 0, max: 100,
                    ticks: { stepSize: 25 },
                    grid: { color: 'rgba(0,0,0,0.05)' }
                },
                x: { grid: { display: false } }
            }
        }
    });
})();
</script>
<?php endif; ?>
</body>
</html>
