<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/budgets/edit.php – Budget editor
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

// Include permissions helper and restrict access to admins and bookkeepers
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper']);

define('ASSET_VERSION', '2025-01-21-BUDGET-EDIT');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

// Get year parameter or default to current year
$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
if ($year < 2000 || $year > 2100) {
    $year = intval(date('Y'));
}

// Fetch user and company
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$firstName = $stmt->fetchColumn() ?: 'User';

$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$companyName = $stmt->fetchColumn() ?: 'Company';

// Fetch accounts (income & expense)
$stmt = $DB->prepare(
    "SELECT account_id, account_code, account_name, account_type
     FROM gl_accounts
     WHERE company_id = ? AND is_active = 1 AND account_type IN ('income','expense')
     ORDER BY account_code"
);
$stmt->execute([$companyId]);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch budgets for the year
$stmt = $DB->prepare(
    "SELECT gl_account_id, period_month, amount_cents
     FROM gl_budgets
     WHERE company_id = ? AND period_year = ? AND project_id IS NULL"
);
$stmt->execute([$companyId, $year]);
$budgetsRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$budgets = [];
foreach ($budgetsRows as $row) {
    $aid = (int)$row['gl_account_id'];
    $m   = (int)$row['period_month'];
    $budgets[$aid][$m] = $row['amount_cents'] / 100.0;
}

// Years list for dropdown (show last 5 years and next 2)
$currentYear = intval(date('Y'));
$years = [];
for ($y = $currentYear - 5; $y <= $currentYear + 2; $y++) {
    if ($y >= 2000 && $y <= 2100) $years[] = $y;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Budgets – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
    <style>
        table.budget-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            font-size: 0.875rem;
        }
        table.budget-table th,
        table.budget-table td {
            border: 1px solid var(--fw-border);
            padding: 0.35rem;
            text-align: center;
        }
        table.budget-table th {
            background: var(--fw-bg-secondary);
        }
        .fw-finance__toolbar {
            margin-top: 1rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .fw-finance__toolbar select {
            padding: 0.4rem;
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
                    <div class="fw-finance__app-name">Edit Budgets</div>
                </div>
            </div>
            <div class="fw-finance__greeting">
                Hello, <span class="fw-finance__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>
            <div class="fw-finance__controls">
                <a href="/finances/" class="fw-finance__back-btn" title="Back to Finance">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
                <button class="fw-finance__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                    <svg class="fw-finance__theme-icon fw-finance__theme-icon--light" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2"/>
                        <line x1="12" y1="1" x2="12" y2="3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="12" y1="21" x2="12" y2="23" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="18.36" y1="18.36" x2="19.78" y2="19.78" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="1" y1="12" x2="3" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="21" y1="12" x2="23" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="18.36" y1="5.64" x2="19.78" y2="4.22" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <svg class="fw-finance__theme-icon fw-finance__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>
        </header>
        <div class="fw-finance__main">
            <div class="fw-finance__toolbar">
                <label for="yearSelect">Year:</label>
                <select id="yearSelect">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="fw-finance__btn" id="changeYearBtn">Change Year</button>
                <button class="fw-finance__btn fw-finance__btn--primary" id="saveBudgetsBtn">Save Budgets</button>
                <div id="saveMessage"></div>
            </div>
            <table class="budget-table">
                <thead>
                    <tr>
                        <th>Account</th>
                        <?php
                        $months = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];
                        foreach ($months as $idx=>$name): ?>
                            <th><?= $name ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody id="budgetTableBody">
                    <?php if (empty($accounts)): ?>
                        <tr><td colspan="13">No income or expense accounts found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($accounts as $acct): ?>
                            <tr>
                                <td style="text-align:left;">
                                    <?= htmlspecialchars($acct['account_code'] . ' - ' . $acct['account_name']) ?>
                                    <input type="hidden" class="acctId" value="<?= (int)$acct['account_id'] ?>">
                                </td>
                                <?php for ($m=1; $m<=12; $m++):
                                    $val = 0.0;
                                    $aid = (int)$acct['account_id'];
                                    if (isset($budgets[$aid][$m])) {
                                        $val = $budgets[$aid][$m];
                                    }
                                ?>
                                <td>
                                    <input type="number" min="0" step="0.01" class="budgetInput" data-month="<?= $m ?>" value="<?= $val != 0.0 ? number_format($val, 2, '.', '') : '' ?>" style="width: 70px;">
                                </td>
                                <?php endfor; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <footer class="fw-finance__footer">
            <span>Finance Budgets v<?= ASSET_VERSION ?></span>
        </footer>
    </div>
</main>
<script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
<script>
document.getElementById('changeYearBtn').addEventListener('click', function() {
    var sel = document.getElementById('yearSelect');
    var yr = sel.value;
    window.location = '/finances/budgets/edit.php?year=' + yr;
});
document.getElementById('saveBudgetsBtn').addEventListener('click', function() {
    var year = document.getElementById('yearSelect').value;
    var rows = document.querySelectorAll('#budgetTableBody tr');
    var budgets = [];
    rows.forEach(function(row) {
        var acctId = parseInt(row.querySelector('.acctId').value, 10);
        var inputs = row.querySelectorAll('input.budgetInput');
        inputs.forEach(function(inp) {
            var month = parseInt(inp.dataset.month, 10);
            var val = inp.value.trim();
            if (val !== '') {
                var amt = parseFloat(val);
                if (amt < 0 || isNaN(amt)) {
                    amt = 0;
                }
                budgets.push({ account_id: acctId, month: month, amount: amt });
            }
        });
    });
    fetch('/finances/budgets/api/save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ year: parseInt(year, 10), budgets: budgets })
    }).then(function(resp) { return resp.json(); }).then(function(data) {
        if (data.success) {
            document.getElementById('saveMessage').innerHTML = '<div class="fw-finance__alert fw-finance__alert--success">Budgets saved.</div>';
            setTimeout(function() { document.getElementById('saveMessage').innerHTML = ''; }, 3000);
        } else {
            document.getElementById('saveMessage').innerHTML = '<div class="fw-finance__alert fw-finance__alert--error">' + (data.message || 'Error saving budgets') + '</div>';
        }
    }).catch(function(err) {
        document.getElementById('saveMessage').innerHTML = '<div class="fw-finance__alert fw-finance__alert--error">' + err.message + '</div>';
    });
});
</script>
</body>
</html>