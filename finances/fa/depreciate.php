<?php
// /finances/fa/depreciate.php – Run depreciation for fixed assets
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

// Include permissions helper and restrict access to admins and bookkeepers
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper']);

define('ASSET_VERSION', '2025-01-21-FA-DEPR');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

// Fetch user and company
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$firstName = $stmt->fetchColumn() ?: 'User';

$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$companyName = $stmt->fetchColumn() ?: 'Company';

// Fetch existing runs for display
$stmt = $DB->prepare(
    "SELECT id, run_month, status, journal_id,
            (SELECT SUM(amount_cents) FROM fa_depreciation_lines WHERE run_id = r.id) as total_cents
     FROM fa_depreciation_runs r
     WHERE company_id = ?
     ORDER BY run_month DESC"
);
$stmt->execute([$companyId]);
$runs = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Run Depreciation – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
    <style>
        .fw-finance__form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-top: 1rem;
        }
        .fw-finance__form label {
            display: flex;
            flex-direction: column;
            font-size: 0.875rem;
        }
        .fw-finance__table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1.5rem;
        }
        .fw-finance__table th,
        .fw-finance__table td {
            border: 1px solid var(--fw-border);
            padding: 0.5rem;
            font-size: 0.875rem;
        }
        .fw-finance__table th {
            background: var(--fw-bg-secondary);
            text-align: left;
        }
    </style>
</head>
<body>
<main class="fw-finance">
    <div class="fw-finance__container">
        <!-- Header -->
        <header class="fw-finance__header">
            <div class="fw-finance__brand">
                <div class="fw-finance__logo-tile">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="fw-finance__brand-text">
                    <div class="fw-finance__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-finance__app-name">Run Depreciation</div>
                </div>
            </div>
            <div class="fw-finance__greeting">
                Hello, <span class="fw-finance__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>
            <div class="fw-finance__controls">
                <a href="/finances/fa/" class="fw-finance__back-btn" title="Back to Fixed Assets">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
            </div>
        </header>
        <div class="fw-finance__main">
            <form id="runForm" class="fw-finance__form" onsubmit="return false;">
                <label>
                    Depreciation Month
                    <input type="month" id="runMonth" value="<?= date('Y-m') ?>" required>
                </label>
                <button type="submit" class="fw-finance__btn fw-finance__btn--primary">Run & Post Depreciation</button>
                <div id="runMessage"></div>
            </form>
            <h3 style="margin-top:2rem;">Previous Runs</h3>
            <table class="fw-finance__table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Status</th>
                        <th>Total</th>
                        <th>Journal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($runs)): ?>
                        <tr><td colspan="4">No runs yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($runs as $r):
                            $total = $r['total_cents'] ? $r['total_cents'] / 100 : 0;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars(date('Y-m', strtotime($r['run_month']))) ?></td>
                            <td><?= htmlspecialchars(ucfirst($r['status'])) ?></td>
                            <td>R <?= number_format($total, 2) ?></td>
                            <td><?= $r['journal_id'] ? '<a href="/finances/journals.php?jid=' . (int)$r['journal_id'] . '">View</a>' : '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <footer class="fw-finance__footer">
            <span>Finance Depreciation v<?= ASSET_VERSION ?></span>
        </footer>
    </div>
</main>
<script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
<script>
document.getElementById('runForm').addEventListener('submit', function() {
    var monthVal = document.getElementById('runMonth').value;
    if (!monthVal) {
        document.getElementById('runMessage').innerHTML = '<div class="fw-finance__alert fw-finance__alert--error">Please select a month.</div>';
        return;
    }
    fetch('/finances/fa/api/run_depreciation.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ run_month: monthVal })
    }).then(function(resp) { return resp.json(); }).then(function(data) {
        if (data.success) {
            document.getElementById('runMessage').innerHTML = '<div class="fw-finance__alert fw-finance__alert--success">' + data.message + '</div>';
            // Reload page after a short delay to show updated runs
            setTimeout(function() { window.location.reload(); }, 1500);
        } else {
            document.getElementById('runMessage').innerHTML = '<div class="fw-finance__alert fw-finance__alert--error">' + (data.message || 'Error running depreciation') + '</div>';
        }
    }).catch(function(err) {
        document.getElementById('runMessage').innerHTML = '<div class="fw-finance__alert fw-finance__alert--error">' + err.message + '</div>';
    });
});
</script>
</body>
</html>