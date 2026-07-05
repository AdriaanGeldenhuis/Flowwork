<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/budgets/edit.php – Budget editor
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

// Include permissions helper and restrict access to admins and bookkeepers
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper']);

define('ASSET_VERSION', FIN_ASSET_VERSION);

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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
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
<body class="fw-finance">
    <div class="fw-finance__container">
        <?php
        $finTitle = 'Edit Budgets';
        $finBack = '/finances/';
        $finCompanyName = $companyName;
        $finFirstName = $firstName;
        include __DIR__ . '/../partials/header.php';
        ?>
        <main class="fw-finance__main">
            <div class="fw-finance__toolbar">
                <label for="yearSelect">Year:</label>
                <select id="yearSelect">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="fw-finance__btn" id="changeYearBtn">Change Year</button>
                <button class="fw-finance__btn fw-finance__btn--primary" id="saveBudgetsBtn">Save Budgets</button>
                <a href="/finances/reports/budget_vs_actual.php" class="fw-finance__btn fw-finance__btn--secondary">View Budget vs Actual</a>
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
        </main>
        <footer class="fw-finance__footer">
            <span>Edit Budgets v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>
    </div>
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
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({ year: parseInt(year, 10), budgets: budgets })
    }).then(function(resp) { return resp.json(); }).then(function(data) {
        if (data.success) {
            document.getElementById('saveMessage').innerHTML = '<div class="fw-finance__alert fw-finance__alert--success">Budgets saved.</div>';
            setTimeout(function() { document.getElementById('saveMessage').innerHTML = ''; }, 3000);
        } else {
            document.getElementById('saveMessage').innerHTML = '<div class="fw-finance__alert fw-finance__alert--error">' + escapeHtml(data.message || 'Error saving budgets') + '</div>';
        }
    }).catch(function(err) {
        document.getElementById('saveMessage').innerHTML = '<div class="fw-finance__alert fw-finance__alert--error">' + escapeHtml(err.message) + '</div>';
    });
});
</script>
</body>
</html>