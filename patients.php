<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['Role']) || $_SESSION['Role'] !== 'practitioner') {
    header("Location: services.php");
    exit();
}
$pageTitle = 'My Patients | CognitiveAI';
require_once 'config.php';
require_once 'header.php';

$accountId   = $_SESSION['AccountID'];
$focusPid    = isset($_GET['pid']) ? (int)$_GET['pid'] : null;

// ── Load all patients for this practitioner ───────────────
$pStmt = sqlsrv_query($conn,
    "SELECT
        P.PATIENT_ID,
        P.FULL_NAME,
        A.USERNAME,
        P.DATE_ADDED,
        (SELECT COUNT(*) FROM ASSESSMENTS AS AS2 WHERE AS2.PATIENT_ID = P.PATIENT_ID) AS TOTAL_ASSESSMENTS,
        (SELECT TOP 1 COGNITIVE_SCORE FROM ASSESSMENTS WHERE PATIENT_ID = P.PATIENT_ID ORDER BY DATE_ASSESSED DESC) AS LAST_SCORE,
        (SELECT TOP 1 DATE_ASSESSED  FROM ASSESSMENTS WHERE PATIENT_ID = P.PATIENT_ID ORDER BY DATE_ASSESSED DESC) AS LAST_DATE
     FROM PATIENTS P
     JOIN ACCOUNTS A ON A.ACCOUNT_ID = P.ACCOUNT_ID
     WHERE P.PRACTITIONER_ID = ?
     ORDER BY P.FULL_NAME ASC",
    [$accountId]
);
$patients = [];
while ($r = sqlsrv_fetch_array($pStmt, SQLSRV_FETCH_ASSOC)) {
    $patients[] = $r;
}

// ── Load assessments for the focused patient ──────────────
$assessments = [];
$focusPatient = null;
if ($focusPid) {
    $fpStmt = sqlsrv_query($conn,
        "SELECT P.FULL_NAME, A.USERNAME FROM PATIENTS P
         JOIN ACCOUNTS A ON A.ACCOUNT_ID = P.ACCOUNT_ID
         WHERE P.PATIENT_ID = ? AND P.PRACTITIONER_ID = ?",
        [$focusPid, $accountId]
    );
    $focusPatient = sqlsrv_fetch_array($fpStmt, SQLSRV_FETCH_ASSOC);

    if ($focusPatient) {
        $aStmt = sqlsrv_query($conn,
            "SELECT ASSESSMENT_ID, DATE_ASSESSED, AGE, GENDER, SLEEP_DURATION, STRESS_LEVEL,
                    DIET_TYPE, DAILY_SCREEN_TIME, EXERCISE_FREQUENCY,
                    CAFFEINE_INTAKE, REACTION_TIME, MEMORY_TEST_SCORE,
                    COGNITIVE_SCORE, NOTES
             FROM ASSESSMENTS
             WHERE PATIENT_ID = ?
             ORDER BY DATE_ASSESSED DESC",
            [$focusPid]
        );
        while ($r = sqlsrv_fetch_array($aStmt, SQLSRV_FETCH_ASSOC)) {
            $assessments[] = $r;
        }
    }
}

function band(float $s): string { return $s >= 75 ? 'high' : ($s >= 50 ? 'mid' : 'low'); }
function bandLabel(float $s): string { return $s >= 75 ? 'High' : ($s >= 50 ? 'Moderate' : 'Low'); }
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <div class="section-title mb-0">My Patients</div>
            <div class="section-sub mb-0"><?= count($patients) ?> patient<?= count($patients) !== 1 ? 's' : '' ?> registered</div>
        </div>
        <a href="product.php" class="btn btn-dark">
            <i class="fas fa-plus me-1"></i>New Assessment
        </a>
    </div>

    <?php if (empty($patients)): ?>
    <div class="cog-card p-5 text-center text-muted">
        <i class="fas fa-users fa-3x mb-3 opacity-25"></i>
        <p>No patients yet. Add your first patient from the <a href="product.php">Assessment</a> page.</p>
    </div>

    <?php else: ?>
    <div class="row g-3">

        <!-- Patient List Sidebar -->
        <div class="col-md-4">
            <div class="cog-card p-0 overflow-hidden">
                <div class="p-3 border-bottom bg-light">
                    <small class="fw-semibold text-uppercase text-muted">Patient List</small>
                </div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($patients as $p): ?>
                    <?php
                        $lastScore = $p['LAST_SCORE'];
                        $isActive  = $focusPid === (int)$p['PATIENT_ID'];
                    ?>
                    <li class="list-group-item list-group-item-action px-3 py-3 <?= $isActive ? 'active' : '' ?>">
                        <a href="patients.php?pid=<?= $p['PATIENT_ID'] ?>" class="text-decoration-none <?= $isActive ? 'text-white' : 'text-dark' ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($p['FULL_NAME']) ?></div>
                                    <small class="<?= $isActive ? 'text-white-50' : 'text-muted' ?>">
                                        @<?= htmlspecialchars($p['USERNAME']) ?>
                                    </small>
                                </div>
                                <div class="text-end">
                                    <?php if ($lastScore !== null): ?>
                                    <span class="badge badge-<?= band((float)$lastScore) ?>">
                                        <?= round($lastScore, 1) ?>
                                    </span>
                                    <?php endif; ?>
                                    <div class="small <?= $isActive ? 'text-white-50' : 'text-muted' ?>">
                                        <?= $p['TOTAL_ASSESSMENTS'] ?> test<?= $p['TOTAL_ASSESSMENTS'] !== 1 ? 's' : '' ?>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <!-- Assessment History Panel -->
        <div class="col-md-8">
            <?php if ($focusPatient): ?>
            <div class="cog-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h5 class="fw-bold mb-0"><?= htmlspecialchars($focusPatient['FULL_NAME']) ?></h5>
                        <small class="text-muted">@<?= htmlspecialchars($focusPatient['USERNAME']) ?></small>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="report.php?pid=<?= $focusPid ?>" class="btn btn-sm btn-outline-secondary" target="_blank">
                            <i class="fas fa-file-medical me-1"></i>Report
                        </a>
                        <a href="product.php" class="btn btn-sm btn-outline-dark">
                            <i class="fas fa-brain me-1"></i>New Assessment
                        </a>
                    </div>
                </div>

                <?php if (empty($assessments)): ?>
                <p class="text-muted">No assessments recorded yet for this patient.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table cog-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Score</th>
                                <th>Age</th>
                                <th>Sleep</th>
                                <th>Stress</th>
                                <th>Reaction</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assessments as $a): ?>
                            <?php $sc = (float)$a['COGNITIVE_SCORE']; ?>
                            <tr>
                                <td><?= fmt_date($a['DATE_ASSESSED'], 'M d, Y') ?></td>
                                <td>
                                    <span class="badge badge-<?= band($sc) ?> fs-6 px-2">
                                        <?= round($sc, 1) ?>
                                    </span>
                                    <div class="small text-muted"><?= bandLabel($sc) ?></div>
                                </td>
                                <td><?= $a['AGE'] ?></td>
                                <td><?= $a['SLEEP_DURATION'] ?>h</td>
                                <td><?= $a['STRESS_LEVEL'] ?>/10</td>
                                <td><?= $a['REACTION_TIME'] ?>ms</td>
                                <td>
                                    <button class="btn btn-sm btn-outline-secondary"
                                        onclick="showDetail(<?= htmlspecialchars(json_encode($a), ENT_QUOTES) ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <div class="cog-card p-5 text-center text-muted">
                <i class="fas fa-arrow-left fa-2x mb-3 opacity-25"></i>
                <p>Select a patient from the list to view their assessment history.</p>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /row -->
    <?php endif; ?>
</div>

<!-- Detail Modal -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Assessment Detail</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailBody"></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showDetail(a) {
    const score = parseFloat(a.COGNITIVE_SCORE);
    const band  = score >= 75 ? 'success' : score >= 50 ? 'warning' : 'danger';
    document.getElementById('detailBody').innerHTML = `
        <div class="row g-3">
            <div class="col-12 text-center">
                <div class="display-4 fw-bold text-${band}">${score.toFixed(2)}<span class="fs-6 text-muted">/100</span></div>
                <div class="text-muted small">Assessed on ${new Date(a.DATE_ASSESSED).toLocaleDateString('en-US',{dateStyle:'long'})}</div>
            </div>
            <div class="col-md-6"><table class="table table-sm">
                <tr><th>Age</th><td>${a.AGE}</td></tr>
                <tr><th>Gender</th><td>${a.GENDER}</td></tr>
                <tr><th>Sleep Duration</th><td>${a.SLEEP_DURATION} hrs</td></tr>
                <tr><th>Stress Level</th><td>${a.STRESS_LEVEL}/10</td></tr>
                <tr><th>Diet Type</th><td>${a.DIET_TYPE}</td></tr>
            </table></div>
            <div class="col-md-6"><table class="table table-sm">
                <tr><th>Screen Time</th><td>${a.DAILY_SCREEN_TIME} hrs</td></tr>
                <tr><th>Exercise</th><td>${a.EXERCISE_FREQUENCY}</td></tr>
                <tr><th>Caffeine</th><td>${a.CAFFEINE_INTAKE} mg</td></tr>
                <tr><th>Reaction Time</th><td>${a.REACTION_TIME} ms</td></tr>
                <tr><th>Memory Score</th><td>${a.MEMORY_TEST_SCORE}</td></tr>
            </table></div>
            ${a.NOTES ? `<div class="col-12"><strong>Notes:</strong><br><p class="text-muted">${a.NOTES}</p></div>` : ''}
        </div>`;
    new bootstrap.Modal(document.getElementById('detailModal')).show();
}
</script>
</body>
</html>
