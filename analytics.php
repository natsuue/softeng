<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['Role']) || !in_array($_SESSION['Role'], ['practitioner','admin'])) {
    header("Location: services.php");
    exit();
}
require_once 'config.php';

$role      = $_SESSION['Role'];
$accountId = $_SESSION['AccountID'];

// ================================================================
// PRACTITIONER DATA
// ================================================================
if ($role === 'practitioner') {

    // Summary stats
    $r = sqlsrv_fetch_array(sqlsrv_query($conn,
        "SELECT COUNT(*) AS total,
                COUNT(DISTINCT PATIENT_ID) AS unique_patients,
                AVG(CAST(COGNITIVE_SCORE AS FLOAT)) AS avg_score
         FROM ASSESSMENTS WHERE PRACTITIONER_ID = ?", [$accountId]
    ), SQLSRV_FETCH_ASSOC);
    $summaryTotal    = (int)($r['total'] ?? 0);
    $summaryPatients = (int)($r['unique_patients'] ?? 0);
    $summaryAvg      = $r['avg_score'] !== null ? round((float)$r['avg_score'], 1) : null;

    // Best month (highest avg score)
    $bmRow = sqlsrv_fetch_array(sqlsrv_query($conn,
        "SELECT TOP 1 YEAR(DATE_ASSESSED) yr, MONTH(DATE_ASSESSED) mo,
                AVG(CAST(COGNITIVE_SCORE AS FLOAT)) avg_score
         FROM ASSESSMENTS WHERE PRACTITIONER_ID = ?
         GROUP BY YEAR(DATE_ASSESSED), MONTH(DATE_ASSESSED)
         ORDER BY avg_score DESC", [$accountId]
    ), SQLSRV_FETCH_ASSOC);
    $bestMonth = $bmRow ? date('M Y', mktime(0,0,0,(int)$bmRow['mo'],1,(int)$bmRow['yr'])) : '—';

    // Monthly trend — last 12 months
    $tStmt = sqlsrv_query($conn,
        "SELECT YEAR(DATE_ASSESSED) yr, MONTH(DATE_ASSESSED) mo,
                AVG(CAST(COGNITIVE_SCORE AS FLOAT)) avg_score,
                COUNT(*) cnt
         FROM ASSESSMENTS
         WHERE PRACTITIONER_ID = ?
           AND DATE_ASSESSED >= DATEADD(MONTH, -11, DATEFROMPARTS(YEAR(GETDATE()), MONTH(GETDATE()), 1))
         GROUP BY YEAR(DATE_ASSESSED), MONTH(DATE_ASSESSED)
         ORDER BY yr ASC, mo ASC", [$accountId]
    );
    $tMap = [];
    while ($r = sqlsrv_fetch_array($tStmt, SQLSRV_FETCH_ASSOC)) {
        $key = $r['yr'] . '-' . str_pad($r['mo'], 2, '0', STR_PAD_LEFT);
        $tMap[$key] = ['avg' => round((float)$r['avg_score'], 1), 'cnt' => (int)$r['cnt']];
    }
    $monthLabels = $scoreData = $volumeData = [];
    for ($i = 11; $i >= 0; $i--) {
        $ts  = strtotime("-$i months");
        $key = date('Y-m', $ts);
        $monthLabels[] = date('M Y', $ts);
        $scoreData[]   = $tMap[$key]['avg'] ?? null;
        $volumeData[]  = $tMap[$key]['cnt'] ?? 0;
    }

    // Score distribution
    $r = sqlsrv_fetch_array(sqlsrv_query($conn,
        "SELECT SUM(CASE WHEN COGNITIVE_SCORE >= 75 THEN 1 ELSE 0 END) hc,
                SUM(CASE WHEN COGNITIVE_SCORE >= 50 AND COGNITIVE_SCORE < 75 THEN 1 ELSE 0 END) mc,
                SUM(CASE WHEN COGNITIVE_SCORE < 50 THEN 1 ELSE 0 END) lc
         FROM ASSESSMENTS WHERE PRACTITIONER_ID = ?", [$accountId]
    ), SQLSRV_FETCH_ASSOC);
    $distData = [(int)($r['hc']??0), (int)($r['mc']??0), (int)($r['lc']??0)];

    // Age group
    $agStmt = sqlsrv_query($conn,
        "SELECT CASE WHEN AGE < 30 THEN 'Under 30'
                     WHEN AGE < 45 THEN '30–44'
                     WHEN AGE < 60 THEN '45–59'
                     ELSE '60+' END AS age_group,
                AVG(CAST(COGNITIVE_SCORE AS FLOAT)) avg_score
         FROM ASSESSMENTS WHERE PRACTITIONER_ID = ?
         GROUP BY CASE WHEN AGE < 30 THEN 'Under 30'
                       WHEN AGE < 45 THEN '30–44'
                       WHEN AGE < 60 THEN '45–59'
                       ELSE '60+' END", [$accountId]
    );
    $ageMap = [];
    while ($r = sqlsrv_fetch_array($agStmt, SQLSRV_FETCH_ASSOC)) {
        $ageMap[$r['age_group']] = round((float)$r['avg_score'], 1);
    }
    $ageOrder  = ['Under 30','30–44','45–59','60+'];
    $ageLabels = $ageOrder;
    $ageData   = array_map(fn($g) => $ageMap[$g] ?? null, $ageOrder);

    // Diet type
    $dStmt = sqlsrv_query($conn,
        "SELECT DIET_TYPE, AVG(CAST(COGNITIVE_SCORE AS FLOAT)) avg_score
         FROM ASSESSMENTS WHERE PRACTITIONER_ID = ?
         GROUP BY DIET_TYPE ORDER BY avg_score DESC", [$accountId]
    );
    $dietLabels = $dietData = [];
    while ($r = sqlsrv_fetch_array($dStmt, SQLSRV_FETCH_ASSOC)) {
        $dietLabels[] = $r['DIET_TYPE'];
        $dietData[]   = round((float)$r['avg_score'], 1);
    }

    // Exercise frequency
    $eStmt = sqlsrv_query($conn,
        "SELECT EXERCISE_FREQUENCY, AVG(CAST(COGNITIVE_SCORE AS FLOAT)) avg_score
         FROM ASSESSMENTS WHERE PRACTITIONER_ID = ?
         GROUP BY EXERCISE_FREQUENCY ORDER BY avg_score DESC", [$accountId]
    );
    $exLabels = $exData = [];
    while ($r = sqlsrv_fetch_array($eStmt, SQLSRV_FETCH_ASSOC)) {
        $exLabels[] = $r['EXERCISE_FREQUENCY'];
        $exData[]   = round((float)$r['avg_score'], 1);
    }

    // Sleep duration buckets
    $slStmt = sqlsrv_query($conn,
        "SELECT CASE WHEN SLEEP_DURATION < 5 THEN '<5 hrs'
                     WHEN SLEEP_DURATION < 7 THEN '5–6 hrs'
                     WHEN SLEEP_DURATION < 8 THEN '7 hrs'
                     WHEN SLEEP_DURATION < 9 THEN '8 hrs'
                     ELSE '9+ hrs' END AS sleep_range,
                AVG(CAST(COGNITIVE_SCORE AS FLOAT)) avg_score
         FROM ASSESSMENTS WHERE PRACTITIONER_ID = ?
         GROUP BY CASE WHEN SLEEP_DURATION < 5 THEN '<5 hrs'
                       WHEN SLEEP_DURATION < 7 THEN '5–6 hrs'
                       WHEN SLEEP_DURATION < 8 THEN '7 hrs'
                       WHEN SLEEP_DURATION < 9 THEN '8 hrs'
                       ELSE '9+ hrs' END", [$accountId]
    );
    $sleepOrder = ['<5 hrs','5–6 hrs','7 hrs','8 hrs','9+ hrs'];
    $sleepMap   = [];
    while ($r = sqlsrv_fetch_array($slStmt, SQLSRV_FETCH_ASSOC)) {
        $sleepMap[$r['sleep_range']] = round((float)$r['avg_score'], 1);
    }
    $sleepLabels = $sleepOrder;
    $sleepData   = array_map(fn($k) => $sleepMap[$k] ?? null, $sleepOrder);

    // Top 5 patients
    $tpStmt = sqlsrv_query($conn,
        "SELECT TOP 5 P.FULL_NAME,
                AVG(CAST(A.COGNITIVE_SCORE AS FLOAT)) avg_score,
                COUNT(*) cnt
         FROM ASSESSMENTS A
         JOIN PATIENTS P ON P.PATIENT_ID = A.PATIENT_ID
         WHERE A.PRACTITIONER_ID = ?
         GROUP BY P.FULL_NAME, P.PATIENT_ID
         ORDER BY avg_score DESC", [$accountId]
    );
    $topPatLabels = $topPatData = [];
    while ($r = sqlsrv_fetch_array($tpStmt, SQLSRV_FETCH_ASSOC)) {
        $topPatLabels[] = $r['FULL_NAME'];
        $topPatData[]   = round((float)$r['avg_score'], 1);
    }

// ================================================================
// ADMIN DATA
// ================================================================
} else {

    // Summary stats
    $r = sqlsrv_fetch_array(sqlsrv_query($conn,
        "SELECT COUNT(*) AS total,
                COUNT(DISTINCT PRACTITIONER_ID) AS practitioners,
                COUNT(DISTINCT PATIENT_ID) AS patients,
                AVG(CAST(COGNITIVE_SCORE AS FLOAT)) AS avg_score
         FROM ASSESSMENTS"
    ), SQLSRV_FETCH_ASSOC);
    $summaryTotal        = (int)($r['total'] ?? 0);
    $summaryPractitioners = (int)($r['practitioners'] ?? 0);
    $summaryPatients     = (int)($r['patients'] ?? 0);
    $summaryAvg          = $r['avg_score'] !== null ? round((float)$r['avg_score'], 1) : null;

    // Monthly assessments — last 12 months
    $tStmt = sqlsrv_query($conn,
        "SELECT YEAR(DATE_ASSESSED) yr, MONTH(DATE_ASSESSED) mo,
                COUNT(*) cnt,
                AVG(CAST(COGNITIVE_SCORE AS FLOAT)) avg_score
         FROM ASSESSMENTS
         WHERE DATE_ASSESSED >= DATEADD(MONTH, -11, DATEFROMPARTS(YEAR(GETDATE()), MONTH(GETDATE()), 1))
         GROUP BY YEAR(DATE_ASSESSED), MONTH(DATE_ASSESSED)
         ORDER BY yr ASC, mo ASC"
    );
    $tMap = [];
    while ($r = sqlsrv_fetch_array($tStmt, SQLSRV_FETCH_ASSOC)) {
        $key = $r['yr'] . '-' . str_pad($r['mo'], 2, '0', STR_PAD_LEFT);
        $tMap[$key] = ['avg' => round((float)$r['avg_score'], 1), 'cnt' => (int)$r['cnt']];
    }
    $monthLabels = $scoreData = $volumeData = [];
    for ($i = 11; $i >= 0; $i--) {
        $ts  = strtotime("-$i months");
        $key = date('Y-m', $ts);
        $monthLabels[] = date('M Y', $ts);
        $scoreData[]   = $tMap[$key]['avg'] ?? null;
        $volumeData[]  = $tMap[$key]['cnt'] ?? 0;
    }

    // Score distribution
    $r = sqlsrv_fetch_array(sqlsrv_query($conn,
        "SELECT SUM(CASE WHEN COGNITIVE_SCORE >= 75 THEN 1 ELSE 0 END) hc,
                SUM(CASE WHEN COGNITIVE_SCORE >= 50 AND COGNITIVE_SCORE < 75 THEN 1 ELSE 0 END) mc,
                SUM(CASE WHEN COGNITIVE_SCORE < 50 THEN 1 ELSE 0 END) lc
         FROM ASSESSMENTS"
    ), SQLSRV_FETCH_ASSOC);
    $distData = [(int)($r['hc']??0), (int)($r['mc']??0), (int)($r['lc']??0)];

    // Top 5 practitioners by assessment count
    $tpStmt = sqlsrv_query($conn,
        "SELECT TOP 5 A.USERNAME,
                COUNT(*) cnt,
                AVG(CAST(AS2.COGNITIVE_SCORE AS FLOAT)) avg_score
         FROM ASSESSMENTS AS2
         JOIN ACCOUNTS A ON A.ACCOUNT_ID = AS2.PRACTITIONER_ID
         GROUP BY A.USERNAME, A.ACCOUNT_ID
         ORDER BY cnt DESC"
    );
    $topPracLabels = $topPracCnt = $topPracAvg = [];
    while ($r = sqlsrv_fetch_array($tpStmt, SQLSRV_FETCH_ASSOC)) {
        $topPracLabels[] = $r['USERNAME'];
        $topPracCnt[]    = (int)$r['cnt'];
        $topPracAvg[]    = round((float)$r['avg_score'], 1);
    }

    // Subscription growth — last 12 months
    $sgStmt = sqlsrv_query($conn,
        "SELECT YEAR(START_DATE) yr, MONTH(START_DATE) mo, COUNT(*) cnt
         FROM SUBSCRIPTIONS
         WHERE START_DATE >= DATEADD(MONTH, -11, DATEFROMPARTS(YEAR(GETDATE()), MONTH(GETDATE()), 1))
         GROUP BY YEAR(START_DATE), MONTH(START_DATE)
         ORDER BY yr ASC, mo ASC"
    );
    $sgMap = [];
    while ($r = sqlsrv_fetch_array($sgStmt, SQLSRV_FETCH_ASSOC)) {
        $key = $r['yr'] . '-' . str_pad($r['mo'], 2, '0', STR_PAD_LEFT);
        $sgMap[$key] = (int)$r['cnt'];
    }
    $subGrowthData = [];
    foreach ($monthLabels as $i => $lbl) {
        $key = date('Y-m', strtotime("-" . (11 - $i) . " months"));
        $subGrowthData[] = $sgMap[$key] ?? 0;
    }

    // Gender breakdown
    $gStmt = sqlsrv_query($conn,
        "SELECT GENDER, COUNT(*) cnt, AVG(CAST(COGNITIVE_SCORE AS FLOAT)) avg_score
         FROM ASSESSMENTS GROUP BY GENDER"
    );
    $genderLabels = $genderCnt = $genderAvg = [];
    while ($r = sqlsrv_fetch_array($gStmt, SQLSRV_FETCH_ASSOC)) {
        $genderLabels[] = $r['GENDER'];
        $genderCnt[]    = (int)$r['cnt'];
        $genderAvg[]    = round((float)$r['avg_score'], 1);
    }
}

$pageTitle = 'Analytics | CognitiveAI';
$pageStyle = '
.welcome-banner {
    background: linear-gradient(135deg, #0d1b2a 0%, #1a3a5c 100%);
    border-radius: 14px; padding: 24px 28px; color: #fff; margin-bottom: 1.5rem;
}
.welcome-banner h4 { font-size: 1.25rem; font-weight: 700; margin: 0 0 3px; }
.welcome-banner small { color: rgba(255,255,255,.6); font-size: .84rem; }

.chart-card {
    background: #fff; border-radius: 12px;
    border: 1px solid #e9ecef; padding: 20px 22px;
    height: 100%;
}
.chart-card h6 {
    font-weight: 700; font-size: .88rem; color: #1a1a2e;
    margin-bottom: 2px;
}
.chart-card .sub {
    font-size: .76rem; color: #adb5bd; margin-bottom: 14px;
}
.empty-chart {
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    height: 140px; color: #adb5bd; font-size: .83rem;
}
';
require_once 'header.php';

// Chart color palettes
$barColors  = ['rgba(26,58,92,0.75)','rgba(26,58,92,0.65)','rgba(26,58,92,0.55)',
               'rgba(26,58,92,0.45)','rgba(26,58,92,0.35)'];
$dietColors = ['rgba(25,135,84,0.75)','rgba(13,110,253,0.7)','rgba(255,193,7,0.75)',
               'rgba(220,53,69,0.7)','rgba(111,66,193,0.7)'];
$exColors   = ['rgba(25,135,84,0.75)','rgba(13,202,240,0.75)',
               'rgba(255,193,7,0.75)','rgba(220,53,69,0.7)'];
$sleepColors = ['rgba(220,53,69,0.7)','rgba(255,193,7,0.75)','rgba(13,202,240,0.75)',
                'rgba(25,135,84,0.75)','rgba(111,66,193,0.7)'];
?>

<div class="container-fluid py-4 px-4">

    <!-- Banner -->
    <div class="welcome-banner">
        <div>
            <h4><i class="fas fa-chart-bar me-2"></i>Data Analytics</h4>
            <small>
                <?= $role === 'practitioner'
                    ? 'In-depth insights across your patients and assessments'
                    : 'System-wide performance and growth metrics' ?>
            </small>
        </div>
        <div class="text-end" style="font-size:.8rem; color:rgba(255,255,255,.45);">
            <?= date('F d, Y') ?>
        </div>
    </div>

<?php if ($role === 'practitioner'): ?>

    <!-- ── Summary Stats ──────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-primary"><i class="fas fa-brain"></i></div>
                <div class="stat-value"><?= $summaryTotal ?></div>
                <div class="stat-label">Total Assessments</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-info"><i class="fas fa-users"></i></div>
                <div class="stat-value"><?= $summaryPatients ?></div>
                <div class="stat-label">Patients Assessed</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-success"><i class="fas fa-chart-line"></i></div>
                <div class="stat-value"><?= $summaryAvg ?? '—' ?></div>
                <div class="stat-label">Overall Avg Score</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-warning"><i class="fas fa-star"></i></div>
                <div class="stat-value" style="font-size:1.1rem;"><?= $bestMonth ?></div>
                <div class="stat-label">Best Month</div>
            </div>
        </div>
    </div>

    <!-- ── Row 1: Trend + Volume ──────────────────────────── -->
    <div class="row g-4 mb-4">
        <div class="col-md-8">
            <div class="chart-card">
                <h6><i class="fas fa-chart-line me-2 text-primary"></i>Monthly Score Trend</h6>
                <div class="sub">Average cognitive score per month — last 12 months</div>
                <?php if (array_filter($scoreData, fn($v) => $v !== null)): ?>
                <canvas id="scoreTrendChart" height="100"></canvas>
                <?php else: ?><div class="empty-chart"><i class="fas fa-chart-line fa-2x mb-2 opacity-25"></i>Not enough data yet</div><?php endif; ?>
            </div>
        </div>
        <div class="col-md-4">
            <div class="chart-card">
                <h6><i class="fas fa-chart-pie me-2 text-warning"></i>Score Distribution</h6>
                <div class="sub">High / Moderate / Low breakdown</div>
                <?php if (array_sum($distData) > 0): ?>
                <canvas id="distChart" height="200"></canvas>
                <?php else: ?><div class="empty-chart"><i class="fas fa-chart-pie fa-2x mb-2 opacity-25"></i>No data yet</div><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Row 2: Volume + Age ────────────────────────────── -->
    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="chart-card">
                <h6><i class="fas fa-calendar-alt me-2 text-info"></i>Assessment Volume</h6>
                <div class="sub">Number of assessments conducted per month</div>
                <?php if (array_sum($volumeData) > 0): ?>
                <canvas id="volumeChart" height="120"></canvas>
                <?php else: ?><div class="empty-chart"><i class="fas fa-chart-bar fa-2x mb-2 opacity-25"></i>No data yet</div><?php endif; ?>
            </div>
        </div>
        <div class="col-md-6">
            <div class="chart-card">
                <h6><i class="fas fa-user-friends me-2 text-success"></i>Avg Score by Age Group</h6>
                <div class="sub">Which age groups score higher on average</div>
                <?php if (array_filter($ageData, fn($v) => $v !== null)): ?>
                <canvas id="ageChart" height="120"></canvas>
                <?php else: ?><div class="empty-chart"><i class="fas fa-chart-bar fa-2x mb-2 opacity-25"></i>No data yet</div><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Row 3: Diet + Exercise ─────────────────────────── -->
    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="chart-card">
                <h6><i class="fas fa-utensils me-2 text-danger"></i>Avg Score by Diet Type</h6>
                <div class="sub">Cognitive performance across dietary patterns</div>
                <?php if (!empty($dietData)): ?>
                <canvas id="dietChart" height="120"></canvas>
                <?php else: ?><div class="empty-chart"><i class="fas fa-utensils fa-2x mb-2 opacity-25"></i>No data yet</div><?php endif; ?>
            </div>
        </div>
        <div class="col-md-6">
            <div class="chart-card">
                <h6><i class="fas fa-running me-2 text-success"></i>Avg Score by Exercise Frequency</h6>
                <div class="sub">Impact of physical activity on cognitive score</div>
                <?php if (!empty($exData)): ?>
                <canvas id="exChart" height="120"></canvas>
                <?php else: ?><div class="empty-chart"><i class="fas fa-running fa-2x mb-2 opacity-25"></i>No data yet</div><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Row 4: Sleep + Top Patients ───────────────────── -->
    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="chart-card">
                <h6><i class="fas fa-bed me-2 text-primary"></i>Avg Score by Sleep Duration</h6>
                <div class="sub">How nightly sleep hours correlate with cognitive performance</div>
                <?php if (array_filter($sleepData, fn($v) => $v !== null)): ?>
                <canvas id="sleepChart" height="120"></canvas>
                <?php else: ?><div class="empty-chart"><i class="fas fa-bed fa-2x mb-2 opacity-25"></i>No data yet</div><?php endif; ?>
            </div>
        </div>
        <div class="col-md-6">
            <div class="chart-card">
                <h6><i class="fas fa-trophy me-2 text-warning"></i>Top 5 Patients by Avg Score</h6>
                <div class="sub">Your highest performing patients</div>
                <?php if (!empty($topPatData)): ?>
                <canvas id="topPatChart" height="120"></canvas>
                <?php else: ?><div class="empty-chart"><i class="fas fa-trophy fa-2x mb-2 opacity-25"></i>No assessments yet</div><?php endif; ?>
            </div>
        </div>
    </div>

<?php else: /* ADMIN */ ?>

    <!-- ── Summary Stats ──────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-primary"><i class="fas fa-brain"></i></div>
                <div class="stat-value"><?= $summaryTotal ?></div>
                <div class="stat-label">Total Assessments</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-info"><i class="fas fa-user-md"></i></div>
                <div class="stat-value"><?= $summaryPractitioners ?></div>
                <div class="stat-label">Active Practitioners</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-success"><i class="fas fa-users"></i></div>
                <div class="stat-value"><?= $summaryPatients ?></div>
                <div class="stat-label">Patients Assessed</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-warning"><i class="fas fa-chart-line"></i></div>
                <div class="stat-value"><?= $summaryAvg ?? '—' ?></div>
                <div class="stat-label">System Avg Score</div>
            </div>
        </div>
    </div>

    <!-- ── Row 1: Volume + Score Trend ───────────────────── -->
    <div class="row g-4 mb-4">
        <div class="col-md-8">
            <div class="chart-card">
                <h6><i class="fas fa-chart-bar me-2 text-primary"></i>Monthly Assessment Volume</h6>
                <div class="sub">System-wide assessments conducted per month — last 12 months</div>
                <?php if (array_sum($volumeData) > 0): ?>
                <canvas id="volumeChart" height="100"></canvas>
                <?php else: ?><div class="empty-chart"><i class="fas fa-chart-bar fa-2x mb-2 opacity-25"></i>No data yet</div><?php endif; ?>
            </div>
        </div>
        <div class="col-md-4">
            <div class="chart-card">
                <h6><i class="fas fa-chart-pie me-2 text-warning"></i>Score Distribution</h6>
                <div class="sub">System-wide High / Moderate / Low</div>
                <?php if (array_sum($distData) > 0): ?>
                <canvas id="distChart" height="200"></canvas>
                <?php else: ?><div class="empty-chart"><i class="fas fa-chart-pie fa-2x mb-2 opacity-25"></i>No data yet</div><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Row 2: Score Trend + Subscription Growth ──────── -->
    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="chart-card">
                <h6><i class="fas fa-chart-line me-2 text-success"></i>Avg Score Trend</h6>
                <div class="sub">System-wide average cognitive score per month</div>
                <?php if (array_filter($scoreData, fn($v) => $v !== null)): ?>
                <canvas id="scoreTrendChart" height="120"></canvas>
                <?php else: ?><div class="empty-chart"><i class="fas fa-chart-line fa-2x mb-2 opacity-25"></i>No data yet</div><?php endif; ?>
            </div>
        </div>
        <div class="col-md-6">
            <div class="chart-card">
                <h6><i class="fas fa-credit-card me-2 text-info"></i>Subscription Growth</h6>
                <div class="sub">New subscriptions per month — last 12 months</div>
                <?php if (array_sum($subGrowthData) > 0): ?>
                <canvas id="subGrowthChart" height="120"></canvas>
                <?php else: ?><div class="empty-chart"><i class="fas fa-credit-card fa-2x mb-2 opacity-25"></i>No subscriptions yet</div><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Row 3: Top Practitioners + Gender ─────────────── -->
    <div class="row g-4 mb-4">
        <div class="col-md-7">
            <div class="chart-card">
                <h6><i class="fas fa-user-md me-2 text-primary"></i>Top 5 Practitioners by Assessments</h6>
                <div class="sub">Most active clinicians on the platform</div>
                <?php if (!empty($topPracCnt)): ?>
                <canvas id="topPracChart" height="120"></canvas>
                <?php else: ?><div class="empty-chart"><i class="fas fa-user-md fa-2x mb-2 opacity-25"></i>No data yet</div><?php endif; ?>
            </div>
        </div>
        <div class="col-md-5">
            <div class="chart-card">
                <h6><i class="fas fa-venus-mars me-2 text-danger"></i>Assessments by Gender</h6>
                <div class="sub">Patient gender distribution across all assessments</div>
                <?php if (!empty($genderCnt)): ?>
                <canvas id="genderChart" height="180"></canvas>
                <?php else: ?><div class="empty-chart"><i class="fas fa-venus-mars fa-2x mb-2 opacity-25"></i>No data yet</div><?php endif; ?>
            </div>
        </div>
    </div>

<?php endif; ?>

</div><!-- /container-fluid -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.font.size   = 11;

const labels12 = <?= json_encode($monthLabels) ?>;

<?php if ($role === 'practitioner'): ?>

// Score Trend
<?php if (array_filter($scoreData, fn($v) => $v !== null)): ?>
new Chart(document.getElementById('scoreTrendChart'), {
    type: 'line',
    data: {
        labels: labels12,
        datasets: [{
            label: 'Avg Score',
            data: <?= json_encode($scoreData) ?>,
            borderColor: '#1a3a5c',
            backgroundColor: 'rgba(26,58,92,0.08)',
            borderWidth: 2.5, pointRadius: 4,
            fill: true, tension: 0.35, spanGaps: true
        }]
    },
    options: { responsive: true, plugins: { legend: { display: false } },
        scales: { y: { min: 0, max: 100, ticks: { stepSize: 25 },
            grid: { color: 'rgba(0,0,0,0.05)' } }, x: { grid: { display: false } } } }
});
<?php endif; ?>

// Distribution Doughnut
<?php if (array_sum($distData) > 0): ?>
new Chart(document.getElementById('distChart'), {
    type: 'doughnut',
    data: {
        labels: ['High (≥75)', 'Moderate (50–74)', 'Low (<50)'],
        datasets: [{ data: <?= json_encode($distData) ?>,
            backgroundColor: ['#198754','#fd7e14','#dc3545'],
            borderWidth: 0, hoverOffset: 6 }]
    },
    options: { responsive: true, cutout: '62%',
        plugins: { legend: { position: 'bottom', labels: { padding: 12 } } } }
});
<?php endif; ?>

// Volume Bar
<?php if (array_sum($volumeData) > 0): ?>
new Chart(document.getElementById('volumeChart'), {
    type: 'bar',
    data: {
        labels: labels12,
        datasets: [{ label: 'Assessments', data: <?= json_encode($volumeData) ?>,
            backgroundColor: 'rgba(13,202,240,0.65)', borderRadius: 5, borderSkipped: false }]
    },
    options: { responsive: true, plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 },
            grid: { color: 'rgba(0,0,0,0.05)' } }, x: { grid: { display: false } } } }
});
<?php endif; ?>

// Age Group
<?php if (array_filter($ageData, fn($v) => $v !== null)): ?>
new Chart(document.getElementById('ageChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($ageLabels) ?>,
        datasets: [{ label: 'Avg Score', data: <?= json_encode($ageData) ?>,
            backgroundColor: <?= json_encode($barColors) ?>,
            borderRadius: 6, borderSkipped: false }]
    },
    options: { responsive: true, plugins: { legend: { display: false } },
        scales: { y: { min: 0, max: 100, ticks: { stepSize: 25 },
            grid: { color: 'rgba(0,0,0,0.05)' } }, x: { grid: { display: false } } } }
});
<?php endif; ?>

// Diet
<?php if (!empty($dietData)): ?>
new Chart(document.getElementById('dietChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($dietLabels) ?>,
        datasets: [{ label: 'Avg Score', data: <?= json_encode($dietData) ?>,
            backgroundColor: <?= json_encode($dietColors) ?>,
            borderRadius: 6, borderSkipped: false }]
    },
    options: { indexAxis: 'y', responsive: true, plugins: { legend: { display: false } },
        scales: { x: { min: 0, max: 100, grid: { color: 'rgba(0,0,0,0.05)' } },
            y: { grid: { display: false } } } }
});
<?php endif; ?>

// Exercise
<?php if (!empty($exData)): ?>
new Chart(document.getElementById('exChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($exLabels) ?>,
        datasets: [{ label: 'Avg Score', data: <?= json_encode($exData) ?>,
            backgroundColor: <?= json_encode($exColors) ?>,
            borderRadius: 6, borderSkipped: false }]
    },
    options: { responsive: true, plugins: { legend: { display: false } },
        scales: { y: { min: 0, max: 100, ticks: { stepSize: 25 },
            grid: { color: 'rgba(0,0,0,0.05)' } }, x: { grid: { display: false } } } }
});
<?php endif; ?>

// Sleep
<?php if (array_filter($sleepData, fn($v) => $v !== null)): ?>
new Chart(document.getElementById('sleepChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($sleepLabels) ?>,
        datasets: [{ label: 'Avg Score', data: <?= json_encode($sleepData) ?>,
            backgroundColor: <?= json_encode($sleepColors) ?>,
            borderRadius: 6, borderSkipped: false }]
    },
    options: { responsive: true, plugins: { legend: { display: false } },
        scales: { y: { min: 0, max: 100, ticks: { stepSize: 25 },
            grid: { color: 'rgba(0,0,0,0.05)' } }, x: { grid: { display: false } } } }
});
<?php endif; ?>

// Top Patients
<?php if (!empty($topPatData)): ?>
new Chart(document.getElementById('topPatChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($topPatLabels) ?>,
        datasets: [{ label: 'Avg Score', data: <?= json_encode($topPatData) ?>,
            backgroundColor: ['rgba(25,135,84,0.8)','rgba(25,135,84,0.65)',
                'rgba(25,135,84,0.5)','rgba(25,135,84,0.38)','rgba(25,135,84,0.25)'],
            borderRadius: 6, borderSkipped: false }]
    },
    options: { indexAxis: 'y', responsive: true, plugins: { legend: { display: false } },
        scales: { x: { min: 0, max: 100, grid: { color: 'rgba(0,0,0,0.05)' } },
            y: { grid: { display: false } } } }
});
<?php endif; ?>

<?php else: /* ADMIN CHARTS */ ?>

// Volume Bar
<?php if (array_sum($volumeData) > 0): ?>
new Chart(document.getElementById('volumeChart'), {
    type: 'bar',
    data: {
        labels: labels12,
        datasets: [{ label: 'Assessments', data: <?= json_encode($volumeData) ?>,
            backgroundColor: 'rgba(26,58,92,0.75)', borderRadius: 5, borderSkipped: false }]
    },
    options: { responsive: true, plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 },
            grid: { color: 'rgba(0,0,0,0.05)' } }, x: { grid: { display: false } } } }
});
<?php endif; ?>

// Distribution Doughnut
<?php if (array_sum($distData) > 0): ?>
new Chart(document.getElementById('distChart'), {
    type: 'doughnut',
    data: {
        labels: ['High (≥75)', 'Moderate (50–74)', 'Low (<50)'],
        datasets: [{ data: <?= json_encode($distData) ?>,
            backgroundColor: ['#198754','#fd7e14','#dc3545'],
            borderWidth: 0, hoverOffset: 6 }]
    },
    options: { responsive: true, cutout: '62%',
        plugins: { legend: { position: 'bottom', labels: { padding: 12 } } } }
});
<?php endif; ?>

// Score Trend Line
<?php if (array_filter($scoreData, fn($v) => $v !== null)): ?>
new Chart(document.getElementById('scoreTrendChart'), {
    type: 'line',
    data: {
        labels: labels12,
        datasets: [{ label: 'Avg Score', data: <?= json_encode($scoreData) ?>,
            borderColor: '#198754', backgroundColor: 'rgba(25,135,84,0.08)',
            borderWidth: 2.5, pointRadius: 4, fill: true, tension: 0.35, spanGaps: true }]
    },
    options: { responsive: true, plugins: { legend: { display: false } },
        scales: { y: { min: 0, max: 100, ticks: { stepSize: 25 },
            grid: { color: 'rgba(0,0,0,0.05)' } }, x: { grid: { display: false } } } }
});
<?php endif; ?>

// Subscription Growth
<?php if (array_sum($subGrowthData) > 0): ?>
new Chart(document.getElementById('subGrowthChart'), {
    type: 'bar',
    data: {
        labels: labels12,
        datasets: [{ label: 'New Subscriptions', data: <?= json_encode($subGrowthData) ?>,
            backgroundColor: 'rgba(13,202,240,0.7)', borderRadius: 5, borderSkipped: false }]
    },
    options: { responsive: true, plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 },
            grid: { color: 'rgba(0,0,0,0.05)' } }, x: { grid: { display: false } } } }
});
<?php endif; ?>

// Top Practitioners
<?php if (!empty($topPracCnt)): ?>
new Chart(document.getElementById('topPracChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($topPracLabels) ?>,
        datasets: [{ label: 'Assessments', data: <?= json_encode($topPracCnt) ?>,
            backgroundColor: ['rgba(26,58,92,0.85)','rgba(26,58,92,0.7)',
                'rgba(26,58,92,0.55)','rgba(26,58,92,0.4)','rgba(26,58,92,0.28)'],
            borderRadius: 6, borderSkipped: false }]
    },
    options: { indexAxis: 'y', responsive: true, plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
            y: { grid: { display: false } } } }
});
<?php endif; ?>

// Gender
<?php if (!empty($genderCnt)): ?>
new Chart(document.getElementById('genderChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($genderLabels) ?>,
        datasets: [{ data: <?= json_encode($genderCnt) ?>,
            backgroundColor: ['rgba(13,110,253,0.75)','rgba(214,51,132,0.75)',
                'rgba(108,117,125,0.65)'],
            borderWidth: 0, hoverOffset: 6 }]
    },
    options: { responsive: true, cutout: '58%',
        plugins: { legend: { position: 'bottom', labels: { padding: 12 } } } }
});
<?php endif; ?>

<?php endif; ?>
</script>
</body>
</html>
