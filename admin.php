<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['Role']) || $_SESSION['Role'] !== 'admin') {
    header("Location: services.php");
    exit();
}
require_once 'config.php';

// ── Handle role/subscription changes ─────────────────────
$actionMsg = '';
if (isset($_POST['update_role'])) {
    $targetId  = (int)$_POST['account_id'];
    $newRole   = in_array($_POST['new_role'], ['practitioner','patient','admin']) ? $_POST['new_role'] : null;
    if ($newRole && $targetId !== $_SESSION['AccountID']) {
        sqlsrv_query($conn, "UPDATE ACCOUNTS SET ROLE = ? WHERE ACCOUNT_ID = ?", [$newRole, $targetId]);
        $actionMsg = "Role updated successfully.";
    }
}
if (isset($_POST['deactivate_sub'])) {
    $subId = (int)$_POST['sub_id'];
    sqlsrv_query($conn, "UPDATE SUBSCRIPTIONS SET STATUS = 'cancelled' WHERE SUBSCRIPTION_ID = ?", [$subId]);
    $actionMsg = "Subscription deactivated.";
}

// ── Dashboard stats ───────────────────────────────────────
$stats = [];

$r = sqlsrv_fetch_array(sqlsrv_query($conn, "SELECT COUNT(*) AS c FROM ACCOUNTS WHERE ROLE = 'practitioner'"), SQLSRV_FETCH_ASSOC);
$stats['practitioners'] = $r['c'];

$r = sqlsrv_fetch_array(sqlsrv_query($conn, "SELECT COUNT(*) AS c FROM ACCOUNTS WHERE ROLE = 'patient'"), SQLSRV_FETCH_ASSOC);
$stats['patients'] = $r['c'];

$r = sqlsrv_fetch_array(sqlsrv_query($conn, "SELECT COUNT(*) AS c FROM SUBSCRIPTIONS WHERE STATUS = 'active' AND END_DATE > GETDATE()"), SQLSRV_FETCH_ASSOC);
$stats['active_subs'] = $r['c'];

$r = sqlsrv_fetch_array(sqlsrv_query($conn, "SELECT COUNT(*) AS c FROM ASSESSMENTS"), SQLSRV_FETCH_ASSOC);
$stats['assessments'] = $r['c'];

$r = sqlsrv_fetch_array(sqlsrv_query($conn, "SELECT AVG(CAST(COGNITIVE_SCORE AS FLOAT)) AS avg FROM ASSESSMENTS"), SQLSRV_FETCH_ASSOC);
$stats['avg_score'] = $r['avg'] !== null ? round($r['avg'], 1) : '—';

// ── Monthly assessments — last 6 months ───────────────────
$monthlyStmt = sqlsrv_query($conn,
    "SELECT YEAR(DATE_ASSESSED) AS yr, MONTH(DATE_ASSESSED) AS mo, COUNT(*) AS cnt
     FROM ASSESSMENTS
     WHERE DATE_ASSESSED >= DATEADD(MONTH, -5, DATEFROMPARTS(YEAR(GETDATE()), MONTH(GETDATE()), 1))
     GROUP BY YEAR(DATE_ASSESSED), MONTH(DATE_ASSESSED)
     ORDER BY yr ASC, mo ASC"
);
$monthlyMap = [];
while ($r = sqlsrv_fetch_array($monthlyStmt, SQLSRV_FETCH_ASSOC)) {
    $monthlyMap[$r['yr'] . '-' . str_pad($r['mo'], 2, '0', STR_PAD_LEFT)] = (int)$r['cnt'];
}
$monthlyLabels = [];
$monthlyData   = [];
for ($i = 5; $i >= 0; $i--) {
    $ts  = strtotime("-$i months");
    $key = date('Y-m', $ts);
    $monthlyLabels[] = date('M Y', $ts);
    $monthlyData[]   = $monthlyMap[$key] ?? 0;
}

// ── System-wide score distribution ───────────────────────
$r = sqlsrv_fetch_array(sqlsrv_query($conn,
    "SELECT
        SUM(CASE WHEN COGNITIVE_SCORE >= 75 THEN 1 ELSE 0 END) AS hc,
        SUM(CASE WHEN COGNITIVE_SCORE >= 50 AND COGNITIVE_SCORE < 75 THEN 1 ELSE 0 END) AS mc,
        SUM(CASE WHEN COGNITIVE_SCORE < 50 THEN 1 ELSE 0 END) AS lc
     FROM ASSESSMENTS"
), SQLSRV_FETCH_ASSOC);
$dist    = [(int)($r['hc'] ?? 0), (int)($r['mc'] ?? 0), (int)($r['lc'] ?? 0)];
$hasDist = array_sum($dist) > 0;

// ── Users table ───────────────────────────────────────────
$roleFilter = $_GET['role'] ?? 'all';
$roleWhere  = in_array($roleFilter, ['practitioner','patient','admin']) ? "WHERE ROLE = '$roleFilter'" : "";
$usersStmt  = sqlsrv_query($conn,
    "SELECT ACCOUNT_ID, USERNAME, EMAIL, ROLE, SUBSCRIPTION, DATE_CREATED FROM ACCOUNTS $roleWhere ORDER BY DATE_CREATED DESC"
);
$users = [];
while ($r = sqlsrv_fetch_array($usersStmt, SQLSRV_FETCH_ASSOC)) $users[] = $r;

// ── Subscriptions table ───────────────────────────────────
$subsStmt = sqlsrv_query($conn,
    "SELECT S.SUBSCRIPTION_ID, A.USERNAME, A.EMAIL, S.PLAN_TYPE, S.START_DATE, S.END_DATE, S.STATUS, S.MONTHLY_LIMIT
     FROM SUBSCRIPTIONS S
     JOIN ACCOUNTS A ON A.ACCOUNT_ID = S.ACCOUNT_ID
     ORDER BY S.START_DATE DESC"
);
$subs = [];
while ($r = sqlsrv_fetch_array($subsStmt, SQLSRV_FETCH_ASSOC)) $subs[] = $r;

// ── Recent assessments ────────────────────────────────────
$recentStmt = sqlsrv_query($conn,
    "SELECT TOP 10 AS2.ASSESSMENT_ID, PA.FULL_NAME AS PATIENT_NAME,
            AC.USERNAME AS PRACTITIONER, AS2.COGNITIVE_SCORE, AS2.DATE_ASSESSED
     FROM ASSESSMENTS AS2
     JOIN PATIENTS PA ON PA.PATIENT_ID = AS2.PATIENT_ID
     JOIN ACCOUNTS AC ON AC.ACCOUNT_ID = AS2.PRACTITIONER_ID
     ORDER BY AS2.DATE_ASSESSED DESC"
);
$recent = [];
while ($r = sqlsrv_fetch_array($recentStmt, SQLSRV_FETCH_ASSOC)) $recent[] = $r;

// ── Greeting ──────────────────────────────────────────────
$hour     = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

$pageTitle = 'Admin Dashboard | CognitiveAI';
$pageStyle = '
.welcome-banner {
    background: linear-gradient(135deg, #1a0533 0%, #3b1060 100%);
    border-radius: 14px; padding: 26px 30px; color: #fff;
    display: flex; align-items: center;
    justify-content: space-between; flex-wrap: wrap; gap: 16px;
    margin-bottom: 1.5rem;
}
.welcome-banner h4 { font-size: 1.35rem; font-weight: 700; margin: 0 0 4px; }
.welcome-banner small { color: rgba(255,255,255,.65); font-size: .85rem; }
.welcome-banner .info-pill {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(255,255,255,.12); border-radius: 20px;
    padding: 4px 14px; font-size: .78rem; color: rgba(255,255,255,.85);
}
.chart-card {
    background: #fff; border-radius: 12px;
    border: 1px solid #e9ecef; padding: 20px 22px;
    height: 100%;
}
.chart-card h6 { font-weight: 700; font-size: .9rem; color: #1a1a2e; margin-bottom: 4px; }
.chart-card .chart-sub { font-size: .78rem; color: #adb5bd; margin-bottom: 16px; }
';
require_once 'header.php';
?>

<div class="container-fluid py-4 px-4">

    <?php if ($actionMsg): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4">
        <?= htmlspecialchars($actionMsg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ── Welcome Banner ────────────────────────────────── -->
    <div class="welcome-banner">
        <div>
            <h4><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($_SESSION['UserName']) ?></h4>
            <small>
                System administrator &nbsp;·&nbsp; <?= date('l, F d, Y') ?>
            </small>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <div class="info-pill">
                <i class="fas fa-user-md"></i> <?= $stats['practitioners'] ?> Practitioners
            </div>
            <div class="info-pill">
                <i class="fas fa-brain"></i> <?= $stats['assessments'] ?> Assessments
            </div>
            <div class="info-pill">
                <i class="fas fa-credit-card"></i> <?= $stats['active_subs'] ?> Active Plans
            </div>
        </div>
    </div>

    <!-- ── Stat Cards ─────────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-2">
            <div class="stat-card">
                <div class="stat-icon text-primary"><i class="fas fa-user-md"></i></div>
                <div class="stat-value"><?= $stats['practitioners'] ?></div>
                <div class="stat-label">Practitioners</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="stat-card">
                <div class="stat-icon text-info"><i class="fas fa-user-injured"></i></div>
                <div class="stat-value"><?= $stats['patients'] ?></div>
                <div class="stat-label">Patients</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="stat-card">
                <div class="stat-icon text-success"><i class="fas fa-credit-card"></i></div>
                <div class="stat-value"><?= $stats['active_subs'] ?></div>
                <div class="stat-label">Active Subs</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="stat-card">
                <div class="stat-icon text-warning"><i class="fas fa-brain"></i></div>
                <div class="stat-value"><?= $stats['assessments'] ?></div>
                <div class="stat-label">Assessments</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="stat-card">
                <div class="stat-icon text-secondary"><i class="fas fa-chart-bar"></i></div>
                <div class="stat-value"><?= $stats['avg_score'] ?></div>
                <div class="stat-label">Avg Score</div>
            </div>
        </div>
    </div>

    <!-- ── Charts ────────────────────────────────────────── -->
    <div class="row g-4 mb-4">
        <div class="col-md-8">
            <div class="chart-card">
                <h6><i class="fas fa-chart-bar me-2 text-primary"></i>Monthly Assessments</h6>
                <div class="chart-sub">Total assessments per month — last 6 months</div>
                <?php if (array_sum($monthlyData) > 0): ?>
                <canvas id="monthlyChart" height="110"></canvas>
                <?php else: ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-chart-bar fa-2x mb-2 opacity-25"></i><br>No assessment data yet.
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-4">
            <div class="chart-card">
                <h6><i class="fas fa-chart-pie me-2 text-warning"></i>Score Distribution</h6>
                <div class="chart-sub">System-wide breakdown by performance band</div>
                <?php if ($hasDist): ?>
                <canvas id="distChart" height="180"></canvas>
                <?php else: ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-chart-pie fa-2x mb-2 opacity-25"></i><br>No assessment data yet.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Tabs ───────────────────────────────────────────── -->
    <ul class="nav nav-tabs mb-4" id="adminTabs">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabUsers">
            <i class="fas fa-users me-1"></i>Accounts (<?= count($users) ?>)
        </a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabSubs">
            <i class="fas fa-credit-card me-1"></i>Subscriptions (<?= count($subs) ?>)
        </a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabAssess">
            <i class="fas fa-brain me-1"></i>Recent Assessments
        </a></li>
    </ul>

    <div class="tab-content">

        <!-- USERS TAB -->
        <div class="tab-pane fade show active" id="tabUsers">
            <div class="cog-card p-3">
                <div class="d-flex gap-2 mb-3 flex-wrap">
                    <a href="admin.php?role=all"          class="btn btn-sm <?= $roleFilter==='all'          ? 'btn-dark' : 'btn-outline-secondary' ?>">All</a>
                    <a href="admin.php?role=practitioner" class="btn btn-sm <?= $roleFilter==='practitioner' ? 'btn-dark' : 'btn-outline-secondary' ?>">Practitioners</a>
                    <a href="admin.php?role=patient"      class="btn btn-sm <?= $roleFilter==='patient'      ? 'btn-dark' : 'btn-outline-secondary' ?>">Patients</a>
                    <a href="admin.php?role=admin"        class="btn btn-sm <?= $roleFilter==='admin'        ? 'btn-dark' : 'btn-outline-secondary' ?>">Admins</a>
                </div>
                <div class="table-responsive">
                    <table class="table cog-table">
                        <thead>
                            <tr>
                                <th>ID</th><th>Username</th><th>Email</th>
                                <th>Role</th><th>Subscription</th><th>Joined</th><th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                            <tr>
                                <td><?= $u['ACCOUNT_ID'] ?></td>
                                <td><?= htmlspecialchars($u['USERNAME']) ?></td>
                                <td class="small text-muted"><?= htmlspecialchars($u['EMAIL']) ?></td>
                                <td>
                                    <span class="badge bg-<?= $u['ROLE']==='admin' ? 'danger' : ($u['ROLE']==='practitioner' ? 'primary' : 'secondary') ?>">
                                        <?= $u['ROLE'] ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($u['SUBSCRIPTION']) ?></td>
                                <td class="small"><?= fmt_date($u['DATE_CREATED'], 'M d, Y') ?></td>
                                <td>
                                    <?php if ($u['ACCOUNT_ID'] !== $_SESSION['AccountID']): ?>
                                    <form method="POST" class="d-flex gap-1">
                                        <input type="hidden" name="account_id" value="<?= $u['ACCOUNT_ID'] ?>">
                                        <select name="new_role" class="form-select form-select-sm" style="width:auto;">
                                            <option value="practitioner" <?= $u['ROLE']==='practitioner'?'selected':'' ?>>Practitioner</option>
                                            <option value="patient"      <?= $u['ROLE']==='patient'?'selected':'' ?>>Patient</option>
                                            <option value="admin"        <?= $u['ROLE']==='admin'?'selected':'' ?>>Admin</option>
                                        </select>
                                        <button type="submit" name="update_role" class="btn btn-sm btn-outline-dark">Set</button>
                                    </form>
                                    <?php else: ?>
                                    <span class="text-muted small">You</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- SUBSCRIPTIONS TAB -->
        <div class="tab-pane fade" id="tabSubs">
            <div class="cog-card p-3">
                <div class="table-responsive">
                    <table class="table cog-table">
                        <thead>
                            <tr>
                                <th>ID</th><th>Practitioner</th><th>Plan</th>
                                <th>Start</th><th>End</th><th>Status</th><th>Limit</th><th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subs as $s): ?>
                            <tr>
                                <td><?= $s['SUBSCRIPTION_ID'] ?></td>
                                <td>
                                    <div class="fw-semibold small"><?= htmlspecialchars($s['USERNAME']) ?></div>
                                    <div class="text-muted" style="font-size:.75rem;"><?= htmlspecialchars($s['EMAIL']) ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $s['PLAN_TYPE']==='premium' ? 'warning text-dark' : 'primary' ?>">
                                        <?= ucfirst($s['PLAN_TYPE']) ?>
                                    </span>
                                </td>
                                <td class="small"><?= fmt_date($s['START_DATE'], 'M d, Y') ?></td>
                                <td class="small"><?= fmt_date($s['END_DATE'], 'M d, Y') ?></td>
                                <td>
                                    <span class="badge bg-<?= $s['STATUS']==='active' ? 'success' : ($s['STATUS']==='cancelled' ? 'danger' : 'secondary') ?>">
                                        <?= $s['STATUS'] ?>
                                    </span>
                                </td>
                                <td><?= $s['MONTHLY_LIMIT'] !== null ? $s['MONTHLY_LIMIT'] : '∞' ?></td>
                                <td>
                                    <?php if ($s['STATUS'] === 'active'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="sub_id" value="<?= $s['SUBSCRIPTION_ID'] ?>">
                                        <button type="submit" name="deactivate_sub" class="btn btn-sm btn-outline-danger"
                                            onclick="return confirm('Deactivate this subscription?')">
                                            Deactivate
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- RECENT ASSESSMENTS TAB -->
        <div class="tab-pane fade" id="tabAssess">
            <div class="cog-card p-3">
                <div class="table-responsive">
                    <table class="table cog-table">
                        <thead>
                            <tr><th>ID</th><th>Patient</th><th>Practitioner</th><th>Score</th><th>Date</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent as $a): ?>
                            <?php $sc = (float)$a['COGNITIVE_SCORE']; ?>
                            <tr>
                                <td><?= $a['ASSESSMENT_ID'] ?></td>
                                <td><?= htmlspecialchars($a['PATIENT_NAME']) ?></td>
                                <td><?= htmlspecialchars($a['PRACTITIONER']) ?></td>
                                <td>
                                    <span class="badge badge-<?= $sc >= 75 ? 'high' : ($sc >= 50 ? 'mid' : 'low') ?> fs-6 px-2">
                                        <?= round($sc, 1) ?>
                                    </span>
                                </td>
                                <td class="small"><?= fmt_date($a['DATE_ASSESSED'], 'M d, Y') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div><!-- /tab-content -->
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
<?php if (array_sum($monthlyData) > 0): ?>
new Chart(document.getElementById('monthlyChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($monthlyLabels) ?>,
        datasets: [{
            label: 'Assessments',
            data: <?= json_encode($monthlyData) ?>,
            backgroundColor: 'rgba(26,58,92,0.75)',
            borderRadius: 5,
            borderSkipped: false
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: 'rgba(0,0,0,0.05)' } },
            x: { grid: { display: false } }
        }
    }
});
<?php endif; ?>

<?php if ($hasDist): ?>
new Chart(document.getElementById('distChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: ['High (≥75)', 'Moderate (50–74)', 'Low (<50)'],
        datasets: [{
            data: <?= json_encode($dist) ?>,
            backgroundColor: ['#198754','#fd7e14','#dc3545'],
            borderWidth: 0,
            hoverOffset: 6
        }]
    },
    options: {
        responsive: true,
        cutout: '65%',
        plugins: {
            legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 12 } }
        }
    }
});
<?php endif; ?>
</script>
</body>
</html>
