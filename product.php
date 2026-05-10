<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['Role']) || $_SESSION['Role'] !== 'practitioner') {
    header("Location: services.php");
    exit();
}
require_once 'config.php';

$accountId = $_SESSION['AccountID'];

// ── Subscription check (must happen before any HTML output) ──
$subStmt = sqlsrv_query($conn,
    "SELECT PLAN_TYPE, END_DATE, MONTHLY_LIMIT FROM SUBSCRIPTIONS
     WHERE ACCOUNT_ID = ? AND STATUS = 'active' AND END_DATE > GETDATE()
     ORDER BY SUBSCRIPTION_ID DESC",
    [$accountId]
);
$sub = sqlsrv_fetch_array($subStmt, SQLSRV_FETCH_ASSOC);

if (!$sub) {
    header("Location: subscription.php?reason=required");
    exit();
}

$pageTitle = 'Run Assessment | CognitiveAI';
$pageStyle = '
.rt-btn {
    width: 160px; height: 160px; border-radius: 50%;
    font-size: 1.4rem; font-weight: 800; border: none;
    cursor: pointer; transition: background 0.15s, transform 0.1s;
    display: block; margin: 20px auto;
}
.rt-waiting { background: #6c757d; color: #fff; cursor: not-allowed; }
.rt-ready   { background: #198754; color: #fff; transform: scale(1.05);
              box-shadow: 0 0 30px rgba(25,135,84,.5); }
.rt-false   { background: #dc3545; color: #fff; }
.rt-clicked { background: #0d6efd; color: #fff; }

.test-card  { border: 2px solid #dee2e6; border-radius: 12px; padding: 24px;
              background: #fff; margin-bottom: 16px; }
.test-card.done { border-color: #198754; background: #f0fff4; }

.mt-sequence { font-size: 2.8rem; font-weight: 800; letter-spacing: 12px;
               text-align: center; color: #333; padding: 20px 0; }
.step-badge  { display: inline-block; background: #333; color: #fff;
               border-radius: 50%; width: 28px; height: 28px; line-height: 28px;
               text-align: center; font-weight: 700; font-size: .85rem; margin-right: 8px; }
.trial-dots  { display: flex; gap: 8px; justify-content: center; margin-top: 8px; }
.trial-dot   { width: 14px; height: 14px; border-radius: 50%; background: #dee2e6; }
.trial-dot.done { background: #198754; }
.trial-dot.active { background: #0d6efd; }
';
require_once 'header.php';

$limitReached = false;
$usageCount   = 0;
if ($sub['MONTHLY_LIMIT'] !== null) {
    $uRow       = sqlsrv_fetch_array(sqlsrv_query($conn,
        "SELECT COUNT(*) AS cnt FROM ASSESSMENTS
         WHERE PRACTITIONER_ID = ?
           AND MONTH(DATE_ASSESSED) = MONTH(GETDATE())
           AND YEAR(DATE_ASSESSED)  = YEAR(GETDATE())",
        [$accountId]
    ), SQLSRV_FETCH_ASSOC);
    $usageCount = $uRow['cnt'] ?? 0;
    if ($usageCount >= $sub['MONTHLY_LIMIT']) $limitReached = true;
}

// ── Load practitioner's patients ──────────────────────────
$pStmt = sqlsrv_query($conn,
    "SELECT PATIENT_ID, FULL_NAME FROM PATIENTS
     WHERE PRACTITIONER_ID = ? ORDER BY FULL_NAME ASC",
    [$accountId]
);
$patientsList = [];
while ($r = sqlsrv_fetch_array($pStmt, SQLSRV_FETCH_ASSOC)) {
    $patientsList[] = $r;
}

// ── Handle Assessment POST ────────────────────────────────
$prediction = null;
$savedOk    = false;
$errorMsg   = null;

if (isset($_POST['predict']) && !$limitReached) {
    $patientId = (int)($_POST['patient_id'] ?? 0);

    $pCheck = sqlsrv_query($conn,
        "SELECT 1 FROM PATIENTS WHERE PATIENT_ID = ? AND PRACTITIONER_ID = ?",
        [$patientId, $accountId]
    );
    if (!sqlsrv_has_rows($pCheck)) {
        $errorMsg = "Invalid patient selected.";
    } else {
        $inputData = [
            "Age"                => (int)   $_POST['Age'],
            "Gender"             =>          $_POST['Gender'],
            "Sleep_Duration"     => (float) $_POST['Sleep_Duration'],
            "Stress_Level"       => (int)   $_POST['Stress_Level'],
            "Diet_Type"          =>          $_POST['Diet_Type'],
            "Daily_Screen_Time"  => (float) $_POST['Daily_Screen_Time'],
            "Exercise_Frequency" =>          $_POST['Exercise_Frequency'],
            "Caffeine_Intake"    => (int)   $_POST['Caffeine_Intake'],
            "Reaction_Time"      => (float) $_POST['Reaction_Time'],
            "Memory_Test_Score"  => (float) $_POST['Memory_Test_Score'],
        ];

        $ch = curl_init("http://localhost:5000/predict");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($inputData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $result = json_decode($response, true);
            if (!empty($result['success'])) {
                $prediction = round($result['cognitive_score'], 2);

                sqlsrv_query($conn,
                    "INSERT INTO ASSESSMENTS
                        (PATIENT_ID, PRACTITIONER_ID, AGE, GENDER, SLEEP_DURATION,
                         STRESS_LEVEL, DIET_TYPE, DAILY_SCREEN_TIME, EXERCISE_FREQUENCY,
                         CAFFEINE_INTAKE, REACTION_TIME, MEMORY_TEST_SCORE, COGNITIVE_SCORE, NOTES)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    [
                        $patientId, $accountId,
                        $inputData['Age'], $inputData['Gender'], $inputData['Sleep_Duration'],
                        $inputData['Stress_Level'], $inputData['Diet_Type'], $inputData['Daily_Screen_Time'],
                        $inputData['Exercise_Frequency'], $inputData['Caffeine_Intake'],
                        $inputData['Reaction_Time'], $inputData['Memory_Test_Score'],
                        $prediction, trim($_POST['notes'] ?? '')
                    ]
                );
                $savedOk = true;
            } else {
                $errorMsg = "AI engine returned an unexpected response.";
            }
        } else {
            $errorMsg = "Could not reach the AI engine. Make sure predict_api.py is running on port 5000.";
        }
    }
}

function scoreBand(float $s): string { return $s >= 75 ? 'high' : ($s >= 50 ? 'mid' : 'low'); }
function scoreBandLabel(float $s): string {
    return $s >= 75 ? 'High Performance' : ($s >= 50 ? 'Moderate Performance' : 'Needs Attention');
}
$subscribed = isset($_GET['subscribed']);
?>

<div class="container py-4">

    <?php if ($subscribed): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4">
        <i class="fas fa-check-circle me-2"></i>Subscription activated! You can now run assessments.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <div class="section-title mb-0">Cognitive Assessment</div>
            <div class="section-sub mb-0">Complete both tests, then fill in the lifestyle questionnaire.</div>
        </div>
        <div class="text-end">
            <span class="badge bg-<?= $sub['PLAN_TYPE'] === 'premium' ? 'warning text-dark' : 'primary' ?> me-1">
                <?= ucfirst($sub['PLAN_TYPE']) ?> Plan
            </span>
            <?php if ($sub['MONTHLY_LIMIT'] !== null): ?>
                <small class="text-muted"><?= $usageCount ?>/<?= $sub['MONTHLY_LIMIT'] ?> this month</small>
            <?php else: ?>
                <small class="text-muted">Unlimited</small>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($limitReached): ?>
    <div class="sub-banner warning mb-4">
        <i class="fas fa-exclamation-triangle fa-lg"></i>
        <div><strong>Monthly limit reached.</strong>
            <a href="subscription.php" class="fw-semibold">Upgrade to Premium</a> for unlimited access.
        </div>
    </div>
    <?php endif; ?>

    <?php if ($prediction !== null): ?>
    <div class="result-box mb-4">
        <div class="score-label mb-2">Predicted Cognitive Score</div>
        <div class="score-value score-<?= scoreBand($prediction) ?>">
            <?= $prediction ?><span style="font-size:1.2rem;opacity:.7">/100</span>
        </div>
        <div class="mt-2">
            <span class="badge badge-<?= scoreBand($prediction) ?> fs-6 px-3 py-2">
                <?= scoreBandLabel($prediction) ?>
            </span>
        </div>
        <?php if ($savedOk): ?>
        <div class="mt-2 small opacity-75"><i class="fas fa-save me-1"></i>Result saved to patient record.</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
    <div class="alert alert-danger mb-4"><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <form method="POST" id="assessmentForm">

                <!-- ── STEP 1: Patient ───────────────────────────── -->
                <div class="cog-card p-4 mb-3">
                    <h6 class="fw-bold mb-3">
                        <span class="step-badge">1</span>Select Patient
                    </h6>
                    <?php if (empty($patientsList)): ?>
                    <p class="text-muted small mb-2">No patients yet. Add one using the panel on the right.</p>
                    <select name="patient_id" class="form-select" disabled>
                        <option>— No patients available —</option>
                    </select>
                    <?php else: ?>
                    <select name="patient_id" class="form-select" required>
                        <option value="">— Select a patient —</option>
                        <?php foreach ($patientsList as $p): ?>
                        <option value="<?= $p['PATIENT_ID'] ?>"
                            <?= (isset($_POST['patient_id']) && $_POST['patient_id'] == $p['PATIENT_ID']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['FULL_NAME']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>

                <!-- ── STEP 2: Interactive Tests ─────────────────── -->
                <div class="cog-card p-4 mb-3">
                    <h6 class="fw-bold mb-1">
                        <span class="step-badge">2</span>Cognitive Tests
                    </h6>
                    <p class="text-muted small mb-3">
                        Both tests must be completed before submitting. Results are recorded automatically.
                    </p>

                    <!-- Hidden inputs populated by JS -->
                    <input type="hidden" name="Reaction_Time"    id="Reaction_Time">
                    <input type="hidden" name="Memory_Test_Score" id="Memory_Test_Score">

                    <!-- Reaction Time Test -->
                    <div class="test-card" id="rtCard">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <h6 class="fw-bold mb-0"><i class="fas fa-bolt text-warning me-2"></i>Reaction Time Test</h6>
                                <small class="text-muted">Click the circle the instant it turns green. 5 rounds.</small>
                            </div>
                            <span id="rtBadge" class="badge bg-secondary">Pending</span>
                        </div>

                        <div id="rtStart" class="text-center py-2">
                            <button type="button" class="btn btn-dark px-4" onclick="RT.start()">
                                <i class="fas fa-play me-1"></i>Start Reaction Test
                            </button>
                        </div>

                        <div id="rtArena" style="display:none;" class="text-center">
                            <p id="rtProgress" class="text-muted small mb-1">Trial 1 of 5</p>
                            <div class="trial-dots" id="rtDots">
                                <div class="trial-dot" id="rd0"></div>
                                <div class="trial-dot" id="rd1"></div>
                                <div class="trial-dot" id="rd2"></div>
                                <div class="trial-dot" id="rd3"></div>
                                <div class="trial-dot" id="rd4"></div>
                            </div>
                            <button type="button" id="rtBtn" class="rt-btn rt-waiting" onclick="RT.click()">
                                Wait...
                            </button>
                            <p id="rtMsg" class="text-muted small mt-1">&nbsp;</p>
                        </div>

                        <div id="rtDone" style="display:none;" class="text-center py-3">
                            <i class="fas fa-check-circle text-success fa-2x mb-2"></i>
                            <div class="fw-semibold">Average: <span id="rtAvg" class="text-success"></span> ms</div>
                        </div>
                    </div>

                    <!-- Memory Test -->
                    <div class="test-card" id="mtCard">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <h6 class="fw-bold mb-0"><i class="fas fa-brain text-primary me-2"></i>Memory Test</h6>
                                <small class="text-muted">Memorize the digit sequence, then type it back. 3 rounds.</small>
                            </div>
                            <span id="mtBadge" class="badge bg-secondary">Pending</span>
                        </div>

                        <div id="mtStart" class="text-center py-2">
                            <button type="button" class="btn btn-dark px-4" onclick="MT.start()">
                                <i class="fas fa-play me-1"></i>Start Memory Test
                            </button>
                        </div>

                        <div id="mtArena" style="display:none;">
                            <p id="mtRoundLabel" class="text-muted small text-center mb-1">Round 1 of 3</p>
                            <div id="mtSequence" class="mt-sequence" style="display:none;"></div>
                            <div id="mtCountdown" class="text-center text-muted small" style="display:none;"></div>
                            <div id="mtInputArea" style="display:none;" class="text-center">
                                <p class="text-muted small">Type the sequence you saw:</p>
                                <input type="text" id="mtInput" class="form-control text-center fw-bold fs-4"
                                       style="max-width:260px;margin:0 auto;letter-spacing:8px;"
                                       maxlength="10" inputmode="numeric"
                                       onkeydown="if(event.key==='Enter'){MT.submit();}">
                                <button type="button" class="btn btn-dark mt-2 px-4" onclick="MT.submit()">Submit</button>
                            </div>
                            <div id="mtFeedback" class="text-center mt-2 fw-semibold" style="display:none;"></div>
                        </div>

                        <div id="mtDone" style="display:none;" class="text-center py-3">
                            <i class="fas fa-check-circle text-success fa-2x mb-2"></i>
                            <div class="fw-semibold">Memory Score: <span id="mtScore" class="text-success"></span>/100</div>
                        </div>
                    </div>

                    <div id="testsCompleteMsg" class="alert alert-success py-2 mt-2" style="display:none;">
                        <i class="fas fa-check-circle me-2"></i>Both tests complete! Fill in the questionnaire below.
                    </div>
                </div>

                <!-- ── STEP 3: Questionnaire ───────────────────── -->
                <div class="cog-card p-4" id="questionnaireSection" style="display:none;">
                    <h6 class="fw-bold mb-3">
                        <span class="step-badge">3</span>Lifestyle Questionnaire
                    </h6>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Age</label>
                            <input type="number" name="Age" class="form-control" min="1" max="120"
                                   value="<?= htmlspecialchars($_POST['Age'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Gender</label>
                            <select name="Gender" class="form-select">
                                <option value="Male"   <?= ($_POST['Gender'] ?? '') === 'Male'   ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= ($_POST['Gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Sleep Duration (hrs/night)</label>
                            <input type="number" step="0.5" name="Sleep_Duration" class="form-control"
                                   min="1" max="14" value="<?= htmlspecialchars($_POST['Sleep_Duration'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Stress Level (1–10)</label>
                            <input type="number" name="Stress_Level" class="form-control" min="1" max="10"
                                   value="<?= htmlspecialchars($_POST['Stress_Level'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Daily Screen Time (hrs)</label>
                            <input type="number" step="0.5" name="Daily_Screen_Time" class="form-control"
                                   min="0" max="24" value="<?= htmlspecialchars($_POST['Daily_Screen_Time'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Diet Type</label>
                            <select name="Diet_Type" class="form-select">
                                <?php foreach (['Balanced','Vegetarian','Vegan','Keto','Mediterranean'] as $d): ?>
                                <option value="<?= $d ?>" <?= ($_POST['Diet_Type'] ?? '') === $d ? 'selected' : '' ?>><?= $d ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Exercise Frequency</label>
                            <select name="Exercise_Frequency" class="form-select">
                                <?php foreach (['Rarely','Occasionally','Frequently','Daily'] as $e): ?>
                                <option value="<?= $e ?>" <?= ($_POST['Exercise_Frequency'] ?? '') === $e ? 'selected' : '' ?>><?= $e ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Caffeine (cups/day)</label>
                            <select name="Caffeine_Intake" class="form-select">
                                <?php
                                $cafVal = $_POST['Caffeine_Intake'] ?? '1';
                                foreach ([0=>'0 — None',1=>'1 cup',2=>'2 cups',3=>'3 cups',4=>'4 cups',5=>'5+ cups'] as $v => $l): ?>
                                <option value="<?= $v ?>" <?= $cafVal == $v ? 'selected' : '' ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Clinical Notes (optional)</label>
                        <textarea name="notes" class="form-control" rows="2"
                                  placeholder="Any observations or context..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                    </div>

                    <button type="submit" name="predict" id="submitBtn"
                            class="btn btn-dark w-100 py-3 fw-bold"
                            <?= ($limitReached || empty($patientsList)) ? 'disabled' : '' ?>>
                        <i class="fas fa-brain me-2"></i>RUN COGNITIVE ANALYSIS
                    </button>
                </div>

            </form>
        </div>

        <!-- ── Patient Panel ─────────────────────────────── -->
        <div class="col-lg-4">
            <div class="cog-card p-4 mb-3">
                <h6 class="fw-bold mb-3"><i class="fas fa-user-plus me-2 text-primary"></i>Add New Patient</h6>
                <p class="text-muted small mb-3">
                    An account will be auto-generated. Share the credentials with your patient.
                </p>
                <input type="text" id="newPatientName" class="form-control mb-2" placeholder="Patient full name">
                <button class="btn btn-dark w-100" onclick="addPatient(event)">
                    <i class="fas fa-plus me-1"></i>Generate Patient Account
                </button>
                <div id="credBox" class="cred-box mt-3 d-none">
                    <div class="fw-semibold mb-2 text-success">
                        <i class="fas fa-check-circle me-1"></i>Account Created!
                    </div>
                    <div><strong>Name:</strong> <span id="cred-name"></span></div>
                    <div><strong>Login URL:</strong> <?= htmlspecialchars((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/SWENG/services.php') ?></div>
                    <div><strong>Username:</strong> <span id="cred-user"></span></div>
                    <div><strong>Password:</strong> <span id="cred-pass"></span></div>
                    <small class="text-danger mt-2 d-block">
                        <i class="fas fa-exclamation-triangle me-1"></i>Password shown once only. Copy it now.
                    </small>
                </div>
            </div>

            <div class="cog-card p-4">
                <h6 class="fw-bold mb-3"><i class="fas fa-users me-2 text-secondary"></i>Your Patients</h6>
                <?php if (empty($patientsList)): ?>
                <p class="text-muted small">No patients yet.</p>
                <?php else: ?>
                <ul class="list-group list-group-flush" id="patientListUL">
                    <?php foreach ($patientsList as $p): ?>
                    <li class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                        <span class="small"><?= htmlspecialchars($p['FULL_NAME']) ?></span>
                        <a href="patients.php?pid=<?= $p['PATIENT_ID'] ?>" class="btn btn-outline-secondary btn-sm py-0">
                            <i class="fas fa-history"></i>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <a href="patients.php" class="btn btn-link btn-sm p-0 mt-2">View all &rarr;</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ================================================================
// REACTION TIME TEST
// ================================================================
const RT = {
    TRIALS: 5,
    completed: 0,
    times: [],
    phase: 'idle',
    timer: null,
    startTime: 0,

    start() {
        this.completed = 0;
        this.times = [];
        this.phase = 'idle';
        document.getElementById('rtStart').style.display = 'none';
        document.getElementById('rtArena').style.display = 'block';
        this.updateDots();
        this.nextTrial();
    },

    nextTrial() {
        const btn = document.getElementById('rtBtn');
        btn.className = 'rt-btn rt-waiting';
        btn.textContent = 'Wait...';
        document.getElementById('rtMsg').textContent = '';
        this.phase = 'waiting';
        const delay = 1500 + Math.random() * 2500;
        this.timer = setTimeout(() => {
            btn.className = 'rt-btn rt-ready';
            btn.textContent = 'CLICK!';
            this.startTime = performance.now();
            this.phase = 'ready';
        }, delay);
    },

    click() {
        if (this.phase === 'waiting') {
            clearTimeout(this.timer);
            const btn = document.getElementById('rtBtn');
            btn.className = 'rt-btn rt-false';
            btn.textContent = 'Too early!';
            document.getElementById('rtMsg').textContent = 'False start — try again';
            this.phase = 'idle';
            setTimeout(() => this.nextTrial(), 1200);
            return;
        }
        if (this.phase !== 'ready') return;

        const rt = Math.round(performance.now() - this.startTime);
        this.times.push(rt);
        this.phase = 'idle';

        const btn = document.getElementById('rtBtn');
        btn.className = 'rt-btn rt-clicked';
        btn.textContent = rt + 'ms';

        const dot = document.getElementById('rd' + this.completed);
        if (dot) dot.classList.replace('active', 'done');

        this.completed++;
        document.getElementById('rtProgress').textContent =
            this.completed < this.TRIALS
                ? `Trial ${this.completed + 1} of ${this.TRIALS}`
                : 'Done!';

        if (this.completed >= this.TRIALS) {
            setTimeout(() => this.finish(), 800);
        } else {
            this.updateDots();
            setTimeout(() => this.nextTrial(), 900);
        }
    },

    updateDots() {
        for (let i = 0; i < this.TRIALS; i++) {
            const d = document.getElementById('rd' + i);
            if (!d) continue;
            d.className = 'trial-dot' +
                (i < this.completed ? ' done' : i === this.completed ? ' active' : '');
        }
    },

    finish() {
        const avg = Math.round(this.times.reduce((a, b) => a + b) / this.times.length);
        document.getElementById('Reaction_Time').value = avg;
        document.getElementById('rtArena').style.display = 'none';
        document.getElementById('rtDone').style.display = 'block';
        document.getElementById('rtAvg').textContent = avg;
        document.getElementById('rtCard').classList.add('done');
        document.getElementById('rtBadge').className = 'badge bg-success';
        document.getElementById('rtBadge').textContent = '✓ Done';
        checkTestsComplete();
    }
};

// ================================================================
// MEMORY TEST
// ================================================================
const MT = {
    rounds: [
        { length: 4, showMs: 3000 },
        { length: 6, showMs: 3500 },
        { length: 8, showMs: 4000 },
    ],
    currentRound: 0,
    scores: [],
    currentSeq: '',
    countdown: null,

    start() {
        this.currentRound = 0;
        this.scores = [];
        document.getElementById('mtStart').style.display = 'none';
        document.getElementById('mtArena').style.display = 'block';
        this.startRound();
    },

    startRound() {
        const r = this.rounds[this.currentRound];
        this.currentSeq = Array.from({length: r.length}, () => Math.floor(Math.random() * 10)).join('');
        document.getElementById('mtRoundLabel').textContent =
            `Round ${this.currentRound + 1} of ${this.rounds.length} — Memorize this sequence:`;
        document.getElementById('mtSequence').textContent = this.currentSeq;
        document.getElementById('mtSequence').style.display = 'block';
        document.getElementById('mtInputArea').style.display = 'none';
        document.getElementById('mtFeedback').style.display = 'none';

        let remaining = Math.ceil(r.showMs / 1000);
        const cdEl = document.getElementById('mtCountdown');
        cdEl.textContent = `Hiding in ${remaining}s`;
        cdEl.style.display = 'block';

        this.countdown = setInterval(() => {
            remaining--;
            cdEl.textContent = remaining > 0 ? `Hiding in ${remaining}s` : '';
        }, 1000);

        setTimeout(() => {
            clearInterval(this.countdown);
            cdEl.style.display = 'none';
            document.getElementById('mtSequence').style.display = 'none';
            document.getElementById('mtInputArea').style.display = 'block';
            document.getElementById('mtInput').value = '';
            document.getElementById('mtInput').focus();
        }, r.showMs);
    },

    submit() {
        const answer = document.getElementById('mtInput').value.trim();
        if (!answer) return;

        const seq = this.currentSeq;
        let correct = 0;
        for (let i = 0; i < seq.length; i++) {
            if (answer[i] === seq[i]) correct++;
        }
        const score = Math.round((correct / seq.length) * 100);
        this.scores.push(score);

        const fb = document.getElementById('mtFeedback');
        fb.style.display = 'block';
        fb.className = 'text-center mt-2 fw-semibold ' + (score >= 75 ? 'text-success' : score >= 50 ? 'text-warning' : 'text-danger');
        fb.textContent = `Sequence was: ${seq}  |  You got: ${correct}/${seq.length} correct (${score}%)`;

        document.getElementById('mtInputArea').style.display = 'none';
        this.currentRound++;

        if (this.currentRound >= this.rounds.length) {
            setTimeout(() => this.finish(), 1500);
        } else {
            setTimeout(() => this.startRound(), 1800);
        }
    },

    finish() {
        const avg = Math.round(this.scores.reduce((a, b) => a + b) / this.scores.length);
        document.getElementById('Memory_Test_Score').value = avg;
        document.getElementById('mtArena').style.display = 'none';
        document.getElementById('mtDone').style.display = 'block';
        document.getElementById('mtScore').textContent = avg;
        document.getElementById('mtCard').classList.add('done');
        document.getElementById('mtBadge').className = 'badge bg-success';
        document.getElementById('mtBadge').textContent = '✓ Done';
        checkTestsComplete();
    }
};

function checkTestsComplete() {
    const rt = document.getElementById('Reaction_Time').value;
    const mt = document.getElementById('Memory_Test_Score').value;
    if (rt && mt) {
        document.getElementById('questionnaireSection').style.display = 'block';
        document.getElementById('testsCompleteMsg').style.display = 'block';
        document.getElementById('questionnaireSection').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

// ================================================================
// ADD PATIENT
// ================================================================
function addPatient(event) {
    const name = document.getElementById('newPatientName').value.trim();
    if (!name) {
        Swal.fire({ icon: 'warning', title: 'Name required', text: 'Please enter the patient\'s full name.', confirmButtonColor: '#333' });
        return;
    }
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Creating...';

    const form = new FormData();
    form.append('full_name', name);

    fetch('add_patient.php', { method: 'POST', body: form })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-plus me-1"></i>Generate Patient Account';
            if (!data.success) {
                Swal.fire({ icon: 'error', title: 'Error', text: data.error, confirmButtonColor: '#333' });
                return;
            }
            document.getElementById('cred-name').textContent = data.full_name;
            document.getElementById('cred-user').textContent = data.username;
            document.getElementById('cred-pass').textContent = data.password;
            document.getElementById('credBox').classList.remove('d-none');
            document.getElementById('newPatientName').value = '';

            const sel = document.querySelector('select[name="patient_id"]');
            if (sel) {
                if (sel.disabled) {
                    sel.disabled = false;
                    sel.innerHTML = '<option value="">— Select a patient —</option>';
                }
                const opt = new Option(data.full_name, data.patient_id, true, true);
                sel.appendChild(opt);
            }
            const ul = document.getElementById('patientListUL');
            if (ul) {
                const li = document.createElement('li');
                li.className = 'list-group-item px-0 py-2 d-flex justify-content-between align-items-center';
                li.innerHTML = `<span class="small">${data.full_name}</span>
                    <a href="patients.php?pid=${data.patient_id}" class="btn btn-outline-secondary btn-sm py-0"><i class="fas fa-history"></i></a>`;
                ul.appendChild(li);
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-plus me-1"></i>Generate Patient Account';
            Swal.fire({ icon: 'error', title: 'Network Error', text: 'Could not reach the server.', confirmButtonColor: '#333' });
        });
}

// If returning from a failed/successful prediction, show the questionnaire
<?php if (isset($_POST['predict'])): ?>
document.getElementById('questionnaireSection').style.display = 'block';
document.getElementById('Reaction_Time').value = '<?= (int)($_POST['Reaction_Time'] ?? 0) ?>';
document.getElementById('Memory_Test_Score').value = '<?= (int)($_POST['Memory_Test_Score'] ?? 0) ?>';
document.getElementById('rtDone').style.display = 'block';
document.getElementById('rtStart').style.display = 'none';
document.getElementById('rtAvg').textContent = '<?= (int)($_POST['Reaction_Time'] ?? 0) ?>';
document.getElementById('mtDone').style.display = 'block';
document.getElementById('mtStart').style.display = 'none';
document.getElementById('mtScore').textContent = '<?= (int)($_POST['Memory_Test_Score'] ?? 0) ?>';
document.getElementById('rtCard').classList.add('done');
document.getElementById('mtCard').classList.add('done');
<?php endif; ?>
</script>
</body>
</html>
