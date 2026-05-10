<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['Role'])) {
    header("Location: services.php");
    exit();
}
require_once 'config.php';

$role      = $_SESSION['Role'];
$accountId = $_SESSION['AccountID'];

// ── Resolve which patient to report on ───────────────────
if ($role === 'patient') {
    $patStmt = sqlsrv_query($conn,
        "SELECT P.PATIENT_ID, P.FULL_NAME, P.DATE_ADDED,
                A2.USERNAME AS PRACTITIONER_NAME, A2.EMAIL AS PRACTITIONER_EMAIL
         FROM PATIENTS P
         JOIN ACCOUNTS A2 ON A2.ACCOUNT_ID = P.PRACTITIONER_ID
         WHERE P.ACCOUNT_ID = ?",
        [$accountId]
    );
    $patient = sqlsrv_fetch_array($patStmt, SQLSRV_FETCH_ASSOC);
} else {
    $pid = (int)($_GET['pid'] ?? 0);
    if (!$pid) { header("Location: " . ($role === 'admin' ? 'admin.php' : 'patients.php')); exit(); }

    $whereExtra = $role === 'practitioner' ? "AND P.PRACTITIONER_ID = $accountId" : "";
    $patStmt = sqlsrv_query($conn,
        "SELECT P.PATIENT_ID, P.FULL_NAME, P.DATE_ADDED,
                A2.USERNAME AS PRACTITIONER_NAME, A2.EMAIL AS PRACTITIONER_EMAIL
         FROM PATIENTS P
         JOIN ACCOUNTS A2 ON A2.ACCOUNT_ID = P.PRACTITIONER_ID
         WHERE P.PATIENT_ID = ? $whereExtra",
        [$pid]
    );
    $patient = sqlsrv_fetch_array($patStmt, SQLSRV_FETCH_ASSOC);
}

if (!$patient) {
    header("Location: " . ($role === 'admin' ? 'admin.php' : ($role === 'patient' ? 'patient_portal.php' : 'patients.php')));
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
     ORDER BY DATE_ASSESSED ASC",
    [$patientId]
);
$assessments = [];
while ($r = sqlsrv_fetch_array($aStmt, SQLSRV_FETCH_ASSOC)) $assessments[] = $r;
$assessmentsDesc = array_reverse($assessments);

$totalTests  = count($assessments);
$latestScore = $totalTests > 0 ? (float)$assessmentsDesc[0]['COGNITIVE_SCORE'] : null;
$avgScore    = $totalTests > 0 ? round(array_sum(array_column($assessments,'COGNITIVE_SCORE')) / $totalTests, 1) : null;
$bestScore   = $totalTests > 0 ? round(max(array_column($assessments,'COGNITIVE_SCORE')), 1) : null;
$scoreDelta  = $totalTests >= 2
    ? round((float)$assessmentsDesc[0]['COGNITIVE_SCORE'] - (float)$assessmentsDesc[1]['COGNITIVE_SCORE'], 1)
    : null;

// ── Score distribution ────────────────────────────────────
$distHigh = count(array_filter($assessments, fn($a) => (float)$a['COGNITIVE_SCORE'] >= 75));
$distMid  = count(array_filter($assessments, fn($a) => (float)$a['COGNITIVE_SCORE'] >= 50 && (float)$a['COGNITIVE_SCORE'] < 75));
$distLow  = count(array_filter($assessments, fn($a) => (float)$a['COGNITIVE_SCORE'] < 50));

// ── Lifestyle averages ────────────────────────────────────
$avgSleep  = $totalTests > 0 ? round(array_sum(array_column($assessments,'SLEEP_DURATION'))  / $totalTests, 1) : null;
$avgStress = $totalTests > 0 ? round(array_sum(array_column($assessments,'STRESS_LEVEL'))     / $totalTests, 1) : null;
$avgScreen = $totalTests > 0 ? round(array_sum(array_column($assessments,'DAILY_SCREEN_TIME'))/ $totalTests, 1) : null;
$avgCaffeine = $totalTests > 0 ? round(array_sum(array_column($assessments,'CAFFEINE_INTAKE'))/ $totalTests, 1) : null;

// Most common diet / exercise
function mostCommon(array $arr, string $key): string {
    $counts = array_count_values(array_column($arr, $key));
    if (empty($counts)) return '—';
    arsort($counts);
    return array_key_first($counts);
}
$topDiet = mostCommon($assessments, 'DIET_TYPE');
$topExercise = mostCommon($assessments, 'EXERCISE_FREQUENCY');
$topGender   = mostCommon($assessments, 'GENDER');
$latestAge   = $totalTests > 0 ? $assessmentsDesc[0]['AGE'] : null;

// ── Chart data ────────────────────────────────────────────
$chartLabels = [];
$chartData   = [];
foreach ($assessments as $i => $a) {
    $chartLabels[] = fmt_date($a['DATE_ASSESSED'], 'M d, Y');
    $chartData[]   = round((float)$a['COGNITIVE_SCORE'], 1);
}

function scoreBand(float $s): string      { return $s >= 75 ? 'High Performance' : ($s >= 50 ? 'Moderate Performance' : 'Needs Attention'); }
function scoreBandColor(float $s): string { return $s >= 75 ? '#198754' : ($s >= 50 ? '#fd7e14' : '#dc3545'); }

$generatedAt = date('F d, Y \a\t g:i A');
$pageTitle   = 'Report — ' . $patient['FULL_NAME'] . ' | CognitiveAI';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
    *, *::before, *::after { box-sizing: border-box; }
    body {
        font-family: 'Inter', sans-serif;
        background: #f0f4f8;
        color: #1a1a2e;
        font-size: 13px;
    }

    /* ── Action bar (hidden on print) ─── */
    .action-bar {
        background: #fff; border-bottom: 1px solid #e9ecef;
        padding: 12px 24px; display: flex;
        align-items: center; justify-content: space-between;
        position: sticky; top: 0; z-index: 100;
    }
    .action-bar .brand { font-weight: 800; font-size: 1rem; color: #0d1b2a; }
    .action-bar .brand span { color: #1a3a5c; }

    /* ── Report wrapper ──────────────── */
    .report-wrap {
        max-width: 820px;
        margin: 28px auto;
        padding: 0 16px 40px;
    }

    /* ── Report paper ────────────────── */
    .report-paper {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 4px 24px rgba(0,0,0,0.08);
        overflow: hidden;
    }

    /* ── Report header ───────────────── */
    .report-header {
        background: linear-gradient(135deg, #0d1b2a 0%, #1a3a5c 100%);
        padding: 28px 36px;
        color: #fff;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
    }
    .rh-brand { font-size: 1.3rem; font-weight: 800; letter-spacing: .5px; }
    .rh-brand span { color: #74c0fc; }
    .rh-title { font-size: .82rem; color: rgba(255,255,255,.55); margin-top: 3px; }
    .rh-right { text-align: right; }
    .rh-date  { font-size: .78rem; color: rgba(255,255,255,.5); }
    .rh-confidential {
        display: inline-block; margin-top: 6px;
        font-size: .68rem; font-weight: 700; letter-spacing: 1px;
        background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.2);
        border-radius: 4px; padding: 2px 8px; color: rgba(255,255,255,.65);
        text-transform: uppercase;
    }

    /* ── Sections ────────────────────── */
    .report-body { padding: 32px 36px; }

    .section { margin-bottom: 28px; }
    .section-heading {
        font-size: .7rem; font-weight: 700; letter-spacing: 1.2px;
        text-transform: uppercase; color: #6c757d;
        border-bottom: 1px solid #e9ecef;
        padding-bottom: 6px; margin-bottom: 14px;
    }

    /* ── Patient info grid ───────────── */
    .info-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 14px;
    }
    .info-item .label { font-size: .72rem; color: #9ca3af; margin-bottom: 2px; }
    .info-item .value { font-size: .88rem; font-weight: 600; color: #1a1a2e; }

    /* ── Score summary row ───────────── */
    .score-row {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
    }
    .score-box {
        border: 1.5px solid #e9ecef;
        border-radius: 10px;
        padding: 14px 12px;
        text-align: center;
    }
    .score-box .sval { font-size: 1.8rem; font-weight: 800; line-height: 1; }
    .score-box .slbl { font-size: .7rem; color: #9ca3af; margin-top: 4px; }
    .score-box .sdelta { font-size: .78rem; font-weight: 600; margin-top: 5px; }
    .delta-up   { color: #198754; }
    .delta-down { color: #dc3545; }
    .delta-flat { color: #9ca3af; }

    /* ── Chart card ──────────────────── */
    .chart-section { margin-bottom: 28px; }

    /* ── Lifestyle grid ──────────────── */
    .lifestyle-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
    }
    .ls-box {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 12px 10px;
        text-align: center;
    }
    .ls-box i   { font-size: 1.2rem; margin-bottom: 6px; }
    .ls-box .lv { font-size: 1rem; font-weight: 700; }
    .ls-box .ll { font-size: .7rem; color: #9ca3af; margin-top: 2px; }

    /* ── History table ───────────────── */
    .rpt-table { width: 100%; border-collapse: collapse; font-size: .78rem; }
    .rpt-table th {
        background: #f1f3f5; font-weight: 700; font-size: .68rem;
        text-transform: uppercase; letter-spacing: .5px;
        padding: 8px 10px; text-align: left;
        border-bottom: 2px solid #dee2e6;
    }
    .rpt-table td { padding: 8px 10px; border-bottom: 1px solid #f1f3f5; vertical-align: top; }
    .rpt-table tr:last-child td { border-bottom: none; }
    .rpt-table tr:hover td { background: #f8f9fa; }

    .score-pill {
        display: inline-block; border-radius: 5px; padding: 2px 8px;
        font-size: .72rem; font-weight: 700; color: #fff;
    }

    /* ── Distribution bar ────────────── */
    .dist-bar { display: flex; height: 12px; border-radius: 6px; overflow: hidden; gap: 2px; }
    .dist-segment { height: 100%; border-radius: 3px; }

    /* ── Footer ──────────────────────── */
    .report-footer {
        border-top: 1px solid #e9ecef;
        padding: 16px 36px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: .72rem;
        color: #9ca3af;
    }

    /* ── Print styles ────────────────── */
    @media print {
        body { background: #fff !important; font-size: 11px; }
        .action-bar { display: none !important; }
        .report-wrap { margin: 0; padding: 0; max-width: 100%; }
        .report-paper { box-shadow: none; border-radius: 0; }
        .report-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .score-box { break-inside: avoid; }
        .section { break-inside: avoid; }
        .rpt-table { font-size: 9px; }
        .rpt-table th, .rpt-table td { padding: 5px 7px; }
        canvas { max-height: 180px !important; }
        @page { margin: 12mm 10mm; size: A4; }
    }
    </style>
</head>
<body>

<!-- Action Bar -->
<div class="action-bar">
    <div class="brand">
        <i class="fas fa-brain me-2" style="color:#1a3a5c;"></i>
        COGNITIVE<span>AI</span>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= $role === 'patient' ? 'patient_portal.php' : ($role === 'admin' ? 'admin.php' : 'patients.php?pid=' . $patientId) ?>"
           class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i>Back
        </a>
        <button onclick="window.print()" class="btn btn-sm btn-dark">
            <i class="fas fa-print me-1"></i>Print / Save PDF
        </button>
    </div>
</div>

<!-- Report Paper -->
<div class="report-wrap">
<div class="report-paper">

    <!-- Header -->
    <div class="report-header">
        <div>
            <div class="rh-brand">COGNITIVE<span>AI</span></div>
            <div class="rh-title">Cognitive Assessment Report</div>
        </div>
        <div class="rh-right">
            <div class="rh-date">Generated: <?= $generatedAt ?></div>
            <div class="rh-confidential"><i class="fas fa-lock me-1"></i>Confidential</div>
        </div>
    </div>

    <div class="report-body">

        <!-- Patient Information -->
        <div class="section">
            <div class="section-heading">Patient Information</div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="label">Full Name</div>
                    <div class="value"><?= htmlspecialchars($patient['FULL_NAME']) ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Age / Gender</div>
                    <div class="value">
                        <?= $latestAge ? $latestAge . ' yrs' : '—' ?>
                        <?= $topGender !== '—' ? ' / ' . $topGender : '' ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="label">Date Registered</div>
                    <div class="value"><?= fmt_date($patient['DATE_ADDED'], 'F d, Y') ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Assigned Clinician</div>
                    <div class="value"><?= htmlspecialchars($patient['PRACTITIONER_NAME']) ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Clinician Email</div>
                    <div class="value" style="font-size:.82rem;"><?= htmlspecialchars($patient['PRACTITIONER_EMAIL']) ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Total Assessments</div>
                    <div class="value"><?= $totalTests ?></div>
                </div>
            </div>
        </div>

        <?php if ($totalTests === 0): ?>
        <div class="text-center text-muted py-4">
            <i class="fas fa-clipboard fa-2x mb-2 opacity-25"></i>
            <p>No assessments recorded yet for this patient.</p>
        </div>
        <?php else: ?>

        <!-- Score Summary -->
        <div class="section">
            <div class="section-heading">Score Summary</div>
            <div class="score-row">
                <div class="score-box">
                    <div class="sval" style="color: <?= scoreBandColor($latestScore) ?>;">
                        <?= round($latestScore, 1) ?>
                    </div>
                    <div class="slbl">Latest Score</div>
                    <?php if ($scoreDelta !== null): ?>
                    <div class="sdelta <?= $scoreDelta > 0 ? 'delta-up' : ($scoreDelta < 0 ? 'delta-down' : 'delta-flat') ?>">
                        <?= $scoreDelta > 0 ? '▲ +' : ($scoreDelta < 0 ? '▼ ' : '● ') ?><?= abs($scoreDelta) ?> vs prev
                    </div>
                    <?php endif; ?>
                </div>
                <div class="score-box">
                    <div class="sval" style="color:#1a3a5c;"><?= $avgScore ?></div>
                    <div class="slbl">Average Score</div>
                </div>
                <div class="score-box">
                    <div class="sval" style="color:#198754;"><?= $bestScore ?></div>
                    <div class="slbl">Best Score</div>
                </div>
                <div class="score-box">
                    <div class="sval" style="color:#6c757d;"><?= $totalTests ?></div>
                    <div class="slbl">Tests Taken</div>
                </div>
            </div>

            <!-- Performance band + distribution bar -->
            <div class="mt-3 d-flex align-items-center gap-3">
                <span class="fw-semibold" style="font-size:.82rem; color: <?= scoreBandColor($latestScore) ?>;">
                    <?= scoreBand($latestScore) ?>
                </span>
                <?php if ($totalTests > 0): ?>
                <div style="flex:1; max-width:260px;">
                    <div class="dist-bar">
                        <?php if ($distHigh > 0): ?><div class="dist-segment bg-success" style="flex:<?= $distHigh ?>"></div><?php endif; ?>
                        <?php if ($distMid  > 0): ?><div class="dist-segment" style="flex:<?= $distMid ?>; background:#fd7e14;"></div><?php endif; ?>
                        <?php if ($distLow  > 0): ?><div class="dist-segment bg-danger" style="flex:<?= $distLow ?>"></div><?php endif; ?>
                    </div>
                    <div class="d-flex gap-2 mt-1" style="font-size:.68rem; color:#9ca3af;">
                        <span style="color:#198754;">■ High: <?= $distHigh ?></span>
                        <span style="color:#fd7e14;">■ Mod: <?= $distMid ?></span>
                        <span style="color:#dc3545;">■ Low: <?= $distLow ?></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Score Trend Chart -->
        <?php if ($totalTests >= 2): ?>
        <div class="section chart-section">
            <div class="section-heading">Score Trend</div>
            <canvas id="trendChart" height="80"></canvas>
        </div>
        <?php endif; ?>

        <!-- Lifestyle Averages -->
        <div class="section">
            <div class="section-heading">Lifestyle Averages (across all assessments)</div>
            <div class="lifestyle-grid">
                <div class="ls-box">
                    <i class="fas fa-bed text-primary"></i>
                    <div class="lv"><?= $avgSleep ?> hrs</div>
                    <div class="ll">Avg Sleep</div>
                </div>
                <div class="ls-box">
                    <i class="fas fa-brain text-danger"></i>
                    <div class="lv"><?= $avgStress ?>/10</div>
                    <div class="ll">Avg Stress</div>
                </div>
                <div class="ls-box">
                    <i class="fas fa-desktop text-info"></i>
                    <div class="lv"><?= $avgScreen ?> hrs</div>
                    <div class="ll">Avg Screen Time</div>
                </div>
                <div class="ls-box">
                    <i class="fas fa-coffee text-warning"></i>
                    <div class="lv"><?= $avgCaffeine ?> cups</div>
                    <div class="ll">Avg Caffeine/Day</div>
                </div>
                <div class="ls-box">
                    <i class="fas fa-utensils text-success"></i>
                    <div class="lv" style="font-size:.82rem;"><?= htmlspecialchars($topDiet) ?></div>
                    <div class="ll">Common Diet</div>
                </div>
                <div class="ls-box">
                    <i class="fas fa-running text-primary"></i>
                    <div class="lv" style="font-size:.82rem;"><?= htmlspecialchars($topExercise) ?></div>
                    <div class="ll">Common Exercise</div>
                </div>
            </div>
        </div>

        <!-- Assessment History -->
        <div class="section">
            <div class="section-heading">Assessment History</div>
            <table class="rpt-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Score</th>
                        <th>Age</th>
                        <th>Sleep</th>
                        <th>Stress</th>
                        <th>Diet</th>
                        <th>Exercise</th>
                        <th>Reaction</th>
                        <th>Memory</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($assessmentsDesc as $i => $a): ?>
                <?php $sc = (float)$a['COGNITIVE_SCORE']; ?>
                <tr>
                    <td style="color:#9ca3af;"><?= $totalTests - $i ?></td>
                    <td><?= fmt_date($a['DATE_ASSESSED'], 'M d, Y') ?></td>
                    <td>
                        <span class="score-pill" style="background: <?= scoreBandColor($sc) ?>;">
                            <?= round($sc, 1) ?>
                        </span>
                    </td>
                    <td><?= $a['AGE'] ?></td>
                    <td><?= $a['SLEEP_DURATION'] ?>h</td>
                    <td><?= $a['STRESS_LEVEL'] ?>/10</td>
                    <td><?= htmlspecialchars($a['DIET_TYPE']) ?></td>
                    <td><?= htmlspecialchars($a['EXERCISE_FREQUENCY']) ?></td>
                    <td><?= $a['REACTION_TIME'] ?>ms</td>
                    <td><?= $a['MEMORY_TEST_SCORE'] ?></td>
                    <td style="color:#6c757d; font-size:.72rem;">
                        <?= !empty($a['NOTES']) ? htmlspecialchars($a['NOTES']) : '—' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php endif; ?>

    </div><!-- /report-body -->

    <!-- Footer -->
    <div class="report-footer">
        <div>
            <i class="fas fa-brain me-1"></i>
            <strong>CognitiveAI</strong> &mdash; AI-Powered Cognitive Assessment Platform
        </div>
        <div>
            <i class="fas fa-lock me-1"></i>
            Confidential &mdash; For authorized medical personnel only
        </div>
    </div>

</div><!-- /report-paper -->
</div><!-- /report-wrap -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($totalTests >= 2): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function() {
    const labels = <?= json_encode($chartLabels) ?>;
    const data   = <?= json_encode($chartData) ?>;
    new Chart(document.getElementById('trendChart').getContext('2d'), {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: 'Cognitive Score',
                data,
                borderColor: '#1a3a5c',
                backgroundColor: 'rgba(26,58,92,0.07)',
                borderWidth: 2,
                pointBackgroundColor: data.map(v => v >= 75 ? '#198754' : (v >= 50 ? '#fd7e14' : '#dc3545')),
                pointRadius: 4,
                fill: true,
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            animation: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { min: 0, max: 100, ticks: { stepSize: 25 },
                     grid: { color: 'rgba(0,0,0,0.05)' } },
                x: { grid: { display: false },
                     ticks: { maxRotation: 45, font: { size: 10 } } }
            }
        }
    });
})();
</script>
<?php endif; ?>
</body>
</html>
