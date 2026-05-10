<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['Role']) || $_SESSION['Role'] !== 'practitioner') {
    header("Location: services.php");
    exit();
}
require_once 'config.php';

$accountId = $_SESSION['AccountID'];

// ── Subscription ──────────────────────────────────────────
$subStmt = sqlsrv_query($conn,
    "SELECT PLAN_TYPE, END_DATE, MONTHLY_LIMIT FROM SUBSCRIPTIONS
     WHERE ACCOUNT_ID = ? AND STATUS = 'active' AND END_DATE > GETDATE()
     ORDER BY SUBSCRIPTION_ID DESC",
    [$accountId]
);
$sub = sqlsrv_fetch_array($subStmt, SQLSRV_FETCH_ASSOC);

// ── Stat: total patients ──────────────────────────────────
$r = sqlsrv_fetch_array(sqlsrv_query($conn,
    "SELECT COUNT(*) AS c FROM PATIENTS WHERE PRACTITIONER_ID = ?", [$accountId]
), SQLSRV_FETCH_ASSOC);
$totalPatients = (int)($r['c'] ?? 0);

// ── Stat: assessments this month ──────────────────────────
$r = sqlsrv_fetch_array(sqlsrv_query($conn,
    "SELECT COUNT(*) AS c FROM ASSESSMENTS
     WHERE PRACTITIONER_ID = ?
       AND MONTH(DATE_ASSESSED) = MONTH(GETDATE())
       AND YEAR(DATE_ASSESSED)  = YEAR(GETDATE())",
    [$accountId]
), SQLSRV_FETCH_ASSOC);
$monthlyCount = (int)($r['c'] ?? 0);

// ── Stat: all-time average score ──────────────────────────
$r = sqlsrv_fetch_array(sqlsrv_query($conn,
    "SELECT AVG(CAST(COGNITIVE_SCORE AS FLOAT)) AS avg FROM ASSESSMENTS
     WHERE PRACTITIONER_ID = ?", [$accountId]
), SQLSRV_FETCH_ASSOC);
$avgScore = $r['avg'] !== null ? round((float)$r['avg'], 1) : null;

// ── Score distribution (High / Moderate / Low) ────────────
$r = sqlsrv_fetch_array(sqlsrv_query($conn,
    "SELECT
        SUM(CASE WHEN COGNITIVE_SCORE >= 75 THEN 1 ELSE 0 END) AS hc,
        SUM(CASE WHEN COGNITIVE_SCORE >= 50 AND COGNITIVE_SCORE < 75 THEN 1 ELSE 0 END) AS mc,
        SUM(CASE WHEN COGNITIVE_SCORE < 50  THEN 1 ELSE 0 END) AS lc
     FROM ASSESSMENTS WHERE PRACTITIONER_ID = ?", [$accountId]
), SQLSRV_FETCH_ASSOC);
$dist = [(int)($r['hc'] ?? 0), (int)($r['mc'] ?? 0), (int)($r['lc'] ?? 0)];
$hasData = array_sum($dist) > 0;

// ── 30-day score trend ────────────────────────────────────
$trendStmt = sqlsrv_query($conn,
    "SELECT CAST(DATE_ASSESSED AS DATE) AS day,
            AVG(CAST(COGNITIVE_SCORE AS FLOAT)) AS avg_score
     FROM ASSESSMENTS
     WHERE PRACTITIONER_ID = ?
       AND DATE_ASSESSED >= DATEADD(DAY, -29, CAST(GETDATE() AS DATE))
     GROUP BY CAST(DATE_ASSESSED AS DATE)
     ORDER BY day ASC",
    [$accountId]
);
$trendMap = [];
while ($r = sqlsrv_fetch_array($trendStmt, SQLSRV_FETCH_ASSOC)) {
    $d = $r['day'] instanceof DateTime ? $r['day']->format('Y-m-d') : (string)$r['day'];
    $trendMap[$d] = round((float)$r['avg_score'], 1);
}

$trendLabels = [];
$trendData   = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $trendLabels[] = date('M d', strtotime($d));
    $trendData[]   = array_key_exists($d, $trendMap) ? $trendMap[$d] : null;
}

// ── Recent 5 assessments ──────────────────────────────────
$recentStmt = sqlsrv_query($conn,
    "SELECT TOP 5 P.FULL_NAME, P.PATIENT_ID,
            A.COGNITIVE_SCORE, A.DATE_ASSESSED
     FROM ASSESSMENTS A
     JOIN PATIENTS P ON P.PATIENT_ID = A.PATIENT_ID
     WHERE A.PRACTITIONER_ID = ?
     ORDER BY A.DATE_ASSESSED DESC",
    [$accountId]
);
$recent = [];
while ($r = sqlsrv_fetch_array($recentStmt, SQLSRV_FETCH_ASSOC)) $recent[] = $r;

// ── Helpers ───────────────────────────────────────────────
$hour     = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$daysLeft = 0;
if ($sub) {
    $endStr   = fmt_date($sub['END_DATE'], 'Y-m-d');
    $daysLeft = (int)ceil((strtotime($endStr) - time()) / 86400);
}

function dBand(float $s): string  { return $s >= 75 ? 'high' : ($s >= 50 ? 'mid' : 'low'); }
function dLabel(float $s): string { return $s >= 75 ? 'High' : ($s >= 50 ? 'Moderate' : 'Low'); }

$pageTitle = 'Dashboard | CognitiveAI';
$pageStyle = '
.welcome-banner {
    background: linear-gradient(135deg, #0d1b2a 0%, #1a3a5c 100%);
    border-radius: 14px; padding: 26px 30px; color: #fff;
    display: flex; align-items: center;
    justify-content: space-between; flex-wrap: wrap; gap: 16px;
}
.welcome-banner h4 { font-size: 1.35rem; font-weight: 700; margin: 0 0 4px; }
.welcome-banner small { color: rgba(255,255,255,.65); font-size: .85rem; }
.welcome-banner .plan-pill {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(255,255,255,.12); border-radius: 20px;
    padding: 4px 12px; font-size: .78rem; color: rgba(255,255,255,.85);
    margin-left: 10px;
}
.welcome-banner .plan-pill.premium { background: rgba(255,212,59,.18); color: #ffd43b; }

.chart-card {
    background: #fff; border-radius: 12px;
    border: 1px solid #e9ecef; padding: 20px 22px;
    height: 100%;
}
.chart-card h6 { font-weight: 700; font-size: .9rem; color: #1a1a2e; margin-bottom: 4px; }
.chart-card .chart-sub { font-size: .78rem; color: #adb5bd; margin-bottom: 16px; }

.usage-bar { height: 6px; background: #e9ecef; border-radius: 6px; overflow: hidden; margin-top: 6px; }
.usage-fill { height: 100%; border-radius: 6px; transition: width .4s; }

.quick-actions .btn { font-size: .85rem; font-weight: 600; }

.days-pill {
    display: inline-block; font-size: .72rem; font-weight: 600;
    border-radius: 20px; padding: 2px 10px; margin-left: 6px; vertical-align: middle;
}
.days-ok      { background: rgba(25,135,84,.15); color: #198754; }
.days-warning { background: rgba(255,193,7,.2);  color: #856404; }
.days-danger  { background: rgba(220,53,69,.15); color: #dc3545; }
';
require_once 'header.php';
?>

<div class="container-fluid py-4 px-4">

    <!-- ── Welcome Banner ──────────────────────────────── -->
    <div class="welcome-banner mb-4">
        <div>
            <h4><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($_SESSION['UserName']) ?></h4>
            <small>
                <?php if ($sub): ?>
                    <span class="plan-pill <?= $sub['PLAN_TYPE'] === 'premium' ? 'premium' : '' ?>">
                        <i class="fas fa-<?= $sub['PLAN_TYPE'] === 'premium' ? 'crown' : 'seedling' ?>"></i>
                        <?= ucfirst($sub['PLAN_TYPE']) ?> Plan
                    </span>
                    &nbsp;·&nbsp; Renews <?= fmt_date($sub['END_DATE'], 'M d, Y') ?>
                    <span class="days-pill <?= $daysLeft <= 5 ? 'days-danger' : ($daysLeft <= 10 ? 'days-warning' : 'days-ok') ?>">
                        <?= $daysLeft ?> day<?= $daysLeft !== 1 ? 's' : '' ?> left
                    </span>
                <?php else: ?>
                    <span style="color:#f87171;">No active subscription</span>
                    &nbsp;·&nbsp; <a href="subscription.php" style="color:#fbbf24;">Subscribe now</a>
                <?php endif; ?>
            </small>
        </div>
        <div class="quick-actions d-flex gap-2 flex-wrap">
            <a href="patients.php" class="btn btn-outline-light btn-sm">
                <i class="fas fa-users me-1"></i>Patients
            </a>
            <a href="product.php" class="btn btn-light btn-sm text-dark fw-bold">
                <i class="fas fa-brain me-1"></i>Run Assessment
            </a>
        </div>
    </div>

    <!-- ── Stat Cards ───────────────────────────────────── -->
    <div class="row g-3 mb-4">

        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-primary"><i class="fas fa-users"></i></div>
                <div class="stat-value"><?= $totalPatients ?></div>
                <div class="stat-label">Total Patients</div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-info"><i class="fas fa-clipboard-check"></i></div>
                <div class="stat-value"><?= $monthlyCount ?></div>
                <div class="stat-label">Tests This Month</div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon text-warning"><i class="fas fa-chart-line"></i></div>
                <div class="stat-value"><?= $avgScore ?? '—' ?></div>
                <div class="stat-label">Avg Cognitive Score</div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon <?= !$sub ? 'text-muted' : ($sub['MONTHLY_LIMIT'] !== null && $monthlyCount >= $sub['MONTHLY_LIMIT'] ? 'text-danger' : 'text-success') ?>">
                    <i class="fas fa-tachometer-alt"></i>
                </div>
                <?php if (!$sub): ?>
                    <div class="stat-value">—</div>
                    <div class="stat-label">No Plan</div>
                <?php elseif ($sub['MONTHLY_LIMIT'] === null): ?>
                    <div class="stat-value" style="font-size:1.1rem;">∞</div>
                    <div class="stat-label">Unlimited Usage</div>
                <?php else: ?>
                    <div class="stat-value"><?= $monthlyCount ?><span style="font-size:.9rem;opacity:.6;">/<?= $sub['MONTHLY_LIMIT'] ?></span></div>
                    <div class="stat-label">Monthly Usage</div>
                    <div class="usage-bar mt-1">
                        <div class="usage-fill" style="
                            width: <?= min(100, round($monthlyCount / $sub['MONTHLY_LIMIT'] * 100)) ?>%;
                            background: <?= $monthlyCount >= $sub['MONTHLY_LIMIT'] ? '#dc3545' : '#198754' ?>;">
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- ── Charts Row ───────────────────────────────────── -->
    <div class="row g-3 mb-4">

        <!-- Score Trend -->
        <div class="col-lg-8">
            <div class="chart-card">
                <h6><i class="fas fa-chart-line me-2 text-primary"></i>Score Trend</h6>
                <div class="chart-sub">Average cognitive score per day — last 30 days</div>
                <?php if (!$hasData): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-chart-line fa-2x mb-2 opacity-25"></i>
                    <p class="small">No assessment data yet. Run your first assessment to see trends.</p>
                </div>
                <?php else: ?>
                <canvas id="trendChart" height="110"></canvas>
                <?php endif; ?>
            </div>
        </div>

        <!-- Score Distribution -->
        <div class="col-lg-4">
            <div class="chart-card">
                <h6><i class="fas fa-chart-pie me-2 text-warning"></i>Score Distribution</h6>
                <div class="chart-sub">All-time breakdown by performance band</div>
                <?php if (!$hasData): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-chart-pie fa-2x mb-2 opacity-25"></i>
                    <p class="small">No data yet.</p>
                </div>
                <?php else: ?>
                <canvas id="distChart" height="180"></canvas>
                <div class="d-flex justify-content-center gap-3 mt-3" style="font-size:.78rem;">
                    <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#198754;margin-right:4px;"></span>High (≥75)</span>
                    <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#fd7e14;margin-right:4px;"></span>Moderate</span>
                    <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#dc3545;margin-right:4px;"></span>Low (&lt;50)</span>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- ── Recent Assessments ───────────────────────────── -->
    <div class="cog-card p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-bold mb-0"><i class="fas fa-history me-2 text-secondary"></i>Recent Assessments</h6>
            <a href="patients.php" class="btn btn-sm btn-outline-secondary">View all &rarr;</a>
        </div>

        <?php if (empty($recent)): ?>
        <div class="text-center text-muted py-4">
            <i class="fas fa-brain fa-2x mb-2 opacity-25"></i>
            <p class="small">No assessments yet. <a href="product.php">Run your first one.</a></p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table cog-table mb-0">
                <thead>
                    <tr><th>Patient</th><th>Score</th><th>Band</th><th>Date</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($recent as $a): ?>
                <?php $sc = (float)$a['COGNITIVE_SCORE']; ?>
                <tr>
                    <td class="fw-semibold"><?= htmlspecialchars($a['FULL_NAME']) ?></td>
                    <td>
                        <span class="badge badge-<?= dBand($sc) ?> fs-6 px-2">
                            <?= round($sc, 1) ?>
                        </span>
                    </td>
                    <td><small class="text-muted"><?= dLabel($sc) ?></small></td>
                    <td><small class="text-muted"><?= fmt_date($a['DATE_ASSESSED'], 'M d, Y') ?></small></td>
                    <td>
                        <a href="patients.php?pid=<?= $a['PATIENT_ID'] ?>"
                           class="btn btn-outline-secondary btn-sm py-0">
                            <i class="fas fa-history"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.color = '#6c757d';

<?php if ($hasData): ?>

// ── Trend Line Chart ──────────────────────────────────────
const trendCtx = document.getElementById('trendChart');
if (trendCtx) {
    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($trendLabels) ?>,
            datasets: [{
                label: 'Avg Score',
                data: <?= json_encode($trendData) ?>,
                borderColor: '#1a3a5c',
                backgroundColor: 'rgba(26,58,92,0.07)',
                borderWidth: 2.5,
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBackgroundColor: '#1a3a5c',
                fill: true,
                tension: 0.35,
                spanGaps: true,
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ' Score: ' + (ctx.parsed.y !== null ? ctx.parsed.y.toFixed(1) : '—')
                    }
                }
            },
            scales: {
                y: {
                    min: 0, max: 100,
                    grid: { color: 'rgba(0,0,0,0.04)' },
                    ticks: { stepSize: 25 }
                },
                x: {
                    grid: { display: false },
                    ticks: {
                        maxTicksLimit: 8,
                        maxRotation: 0,
                    }
                }
            }
        }
    });
}

// ── Distribution Donut Chart ──────────────────────────────
const distCtx = document.getElementById('distChart');
if (distCtx) {
    new Chart(distCtx, {
        type: 'doughnut',
        data: {
            labels: ['High (≥75)', 'Moderate (50–74)', 'Low (<50)'],
            datasets: [{
                data: <?= json_encode($dist) ?>,
                backgroundColor: ['#198754', '#fd7e14', '#dc3545'],
                borderWidth: 0,
                hoverOffset: 6,
            }]
        },
        options: {
            responsive: true,
            cutout: '68%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ' + ctx.label + ': ' + ctx.parsed + ' patients'
                    }
                }
            }
        }
    });
}

<?php endif; ?>
</script>
</body>
</html>
