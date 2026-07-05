<?php
// /finances/fa/depreciate.php – Run depreciation for fixed assets
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

// Include permissions helper and restrict access to admins and bookkeepers
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper']);

define('ASSET_VERSION', FIN_ASSET_VERSION);

require_once __DIR__ . '/../lib/Csrf.php';

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
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
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
<body class="fw-finance">
    <div class="fw-finance__container">
        <?php
        $finTitle = 'Run Depreciation';
        $finBack = '/finances/fa/';
        $finCompanyName = $companyName;
        $finFirstName = $firstName;
        include __DIR__ . '/../partials/header.php';
        ?>
        <main class="fw-finance__main">
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
        </main>
        <footer class="fw-finance__footer">
            <span>Run Depreciation v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>
    </div>
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
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
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