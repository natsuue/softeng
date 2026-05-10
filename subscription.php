<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['Role']) || $_SESSION['Role'] !== 'practitioner') {
    header("Location: services.php");
    exit();
}
require_once 'config.php';

$accountId = $_SESSION['AccountID'];
$message   = '';
$msgType   = 'success';

// ============================================================
// Handle plan subscription POST (must be before header.php)
// ============================================================
if (isset($_POST['subscribe'])) {
    $plan = $_POST['plan'] ?? '';

    if (!in_array($plan, ['basic', 'premium'])) {
        $message = 'Invalid plan selected.';
        $msgType = 'danger';
    } else {
        $limit   = ($plan === 'basic') ? 10 : null;
        $endDate = date('Y-m-d H:i:s', strtotime('+30 days'));

        sqlsrv_query($conn,
            "UPDATE SUBSCRIPTIONS SET STATUS = 'cancelled' WHERE ACCOUNT_ID = ? AND STATUS = 'active'",
            [$accountId]
        );

        $ins = sqlsrv_query($conn,
            "INSERT INTO SUBSCRIPTIONS (ACCOUNT_ID, PLAN_TYPE, START_DATE, END_DATE, STATUS, MONTHLY_LIMIT)
             VALUES (?, ?, GETDATE(), ?, 'active', ?)",
            [$accountId, $plan, $endDate, $limit]
        );

        if ($ins) {
            sqlsrv_query($conn,
                "UPDATE ACCOUNTS SET SUBSCRIPTION = ? WHERE ACCOUNT_ID = ?",
                [$plan, $accountId]
            );
            $_SESSION['Subscription'] = $plan;

            header("Location: product.php?subscribed=1");
            exit();
        } else {
            $err = sqlsrv_errors();
            $message = 'Subscription failed: ' . ($err[0]['message'] ?? 'Unknown error');
            $msgType = 'danger';
        }
    }
}

// ============================================================
// Load current subscription
// ============================================================
$subStmt = sqlsrv_query($conn,
    "SELECT PLAN_TYPE, START_DATE, END_DATE, STATUS, MONTHLY_LIMIT
     FROM SUBSCRIPTIONS
     WHERE ACCOUNT_ID = ? AND STATUS = 'active' AND END_DATE > GETDATE()
     ORDER BY SUBSCRIPTION_ID DESC",
    [$accountId]
);
$currentSub = sqlsrv_fetch_array($subStmt, SQLSRV_FETCH_ASSOC);

// Monthly usage count (for basic plan)
$usageCount = 0;
if ($currentSub) {
    $usageStmt = sqlsrv_query($conn,
        "SELECT COUNT(*) AS cnt FROM ASSESSMENTS
         WHERE PRACTITIONER_ID = ?
           AND MONTH(DATE_ASSESSED) = MONTH(GETDATE())
           AND YEAR(DATE_ASSESSED)  = YEAR(GETDATE())",
        [$accountId]
    );
    $usageRow   = sqlsrv_fetch_array($usageStmt, SQLSRV_FETCH_ASSOC);
    $usageCount = $usageRow['cnt'] ?? 0;
}

$welcome = isset($_GET['welcome']);

$pageTitle = 'Subscription | CognitiveAI';
require_once 'header.php';
?>

<div class="container py-5">

    <?php if ($welcome): ?>
    <div class="alert alert-info mb-4">
        <i class="fas fa-hand-wave me-2"></i>
        Welcome to CognitiveAI! Choose a plan below to start running patient assessments.
    </div>
    <?php endif; ?>

    <?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?> mb-4"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Current Plan Status -->
    <?php if ($currentSub): ?>
    <div class="sub-banner active mb-4">
        <i class="fas fa-check-circle fa-lg"></i>
        <div>
            <strong><?= ucfirst($currentSub['PLAN_TYPE']) ?> Plan — Active</strong><br>
            <small>
                Renews: <?= fmt_date($currentSub['END_DATE'], 'M d, Y') ?>
                &nbsp;|&nbsp;
                <?php if ($currentSub['MONTHLY_LIMIT'] !== null): ?>
                    Usage this month: <strong><?= $usageCount ?> / <?= $currentSub['MONTHLY_LIMIT'] ?></strong> assessments
                <?php else: ?>
                    <strong>Unlimited</strong> assessments
                <?php endif; ?>
            </small>
        </div>
        <a href="product.php" class="btn btn-success btn-sm ms-auto">
            <i class="fas fa-brain me-1"></i>Run Assessment
        </a>
    </div>
    <?php else: ?>
    <div class="sub-banner inactive mb-4">
        <i class="fas fa-exclamation-circle fa-lg"></i>
        <strong>No active subscription.</strong> &nbsp;Select a plan below to access assessments.
    </div>
    <?php endif; ?>

    <div class="section-title">Choose Your Plan</div>
    <p class="section-sub">All plans include a 30-day cycle. Cancel or upgrade anytime.</p>

    <div class="row g-4 justify-content-center">

        <!-- BASIC PLAN -->
        <div class="col-md-4">
            <div class="plan-card h-100">
                <div class="text-center mb-3">
                    <i class="fas fa-seedling fa-2x text-success mb-2"></i>
                    <h4 class="fw-bold mb-0">Basic</h4>
                    <div class="plan-price mt-2">₱499</div>
                    <div class="plan-period">per month</div>
                </div>
                <hr>
                <div class="plan-feature"><i class="fas fa-check text-success me-2"></i>10 assessments / month</div>
                <div class="plan-feature"><i class="fas fa-check text-success me-2"></i>Patient account generation</div>
                <div class="plan-feature"><i class="fas fa-check text-success me-2"></i>Result history storage</div>
                <div class="plan-feature"><i class="fas fa-times text-danger me-2"></i>Unlimited assessments</div>
                <div class="plan-feature"><i class="fas fa-times text-danger me-2"></i>Priority support</div>
                <form method="POST" class="mt-4">
                    <input type="hidden" name="plan" value="basic">
                    <button type="submit" name="subscribe"
                        class="btn <?= ($currentSub && $currentSub['PLAN_TYPE'] === 'basic') ? 'btn-outline-secondary' : 'btn-outline-dark' ?> w-100 fw-semibold">
                        <?= ($currentSub && $currentSub['PLAN_TYPE'] === 'basic') ? 'Current Plan' : 'Subscribe — Basic' ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- PREMIUM PLAN -->
        <div class="col-md-4">
            <div class="plan-card recommended h-100">
                <div class="plan-badge">Most Popular</div>
                <div class="text-center mb-3">
                    <i class="fas fa-crown fa-2x text-warning mb-2"></i>
                    <h4 class="fw-bold mb-0">Premium</h4>
                    <div class="plan-price mt-2">₱999</div>
                    <div class="plan-period">per month</div>
                </div>
                <hr>
                <div class="plan-feature"><i class="fas fa-check text-success me-2"></i><strong>Unlimited</strong> assessments</div>
                <div class="plan-feature"><i class="fas fa-check text-success me-2"></i>Patient account generation</div>
                <div class="plan-feature"><i class="fas fa-check text-success me-2"></i>Result history storage</div>
                <div class="plan-feature"><i class="fas fa-check text-success me-2"></i>Detailed score analytics</div>
                <div class="plan-feature"><i class="fas fa-check text-success me-2"></i>Priority support</div>
                <form method="POST" class="mt-4">
                    <input type="hidden" name="plan" value="premium">
                    <button type="submit" name="subscribe"
                        class="btn <?= ($currentSub && $currentSub['PLAN_TYPE'] === 'premium') ? 'btn-outline-secondary' : 'btn-primary' ?> w-100 fw-semibold">
                        <?= ($currentSub && $currentSub['PLAN_TYPE'] === 'premium') ? 'Current Plan' : 'Subscribe — Premium' ?>
                    </button>
                </form>
            </div>
        </div>

    </div><!-- /row -->

    <?php if ($currentSub): ?>
    <div class="text-center mt-4">
        <form method="POST" onsubmit="return confirm('Cancel your subscription? You will lose access at the end of the current cycle.');">
            <input type="hidden" name="plan" value="cancel">
            <button type="button" class="btn btn-link text-danger btn-sm"
                onclick="cancelSub()">Cancel Subscription</button>
        </form>
    </div>
    <script>
    function cancelSub() {
        Swal.fire({
            title: 'Cancel Subscription?',
            text: 'You will keep access until your billing period ends.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, cancel it'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch('cancel_subscription.php', { method: 'POST' })
                    .then(() => location.reload());
            }
        });
    }
    </script>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
