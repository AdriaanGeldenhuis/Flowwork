<?php
// /finances/fa/asset_new.php – Create a new fixed asset
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

// Fetch GL accounts (for asset, expense, and accumulated)
$stmt = $DB->prepare(
    "SELECT account_id, account_code, account_name, account_type
     FROM gl_accounts
     WHERE company_id = ? AND is_active = 1
     ORDER BY account_code"
);
$stmt->execute([$companyId]);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Separate accounts by type
$assetAccounts = [];
$expenseAccounts = [];
foreach ($accounts as $acc) {
    if (strtolower($acc['account_type']) === 'asset') {
        $assetAccounts[] = $acc;
    }
    if (strtolower($acc['account_type']) === 'expense') {
        $expenseAccounts[] = $acc;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Fixed Asset – <?= htmlspecialchars($companyName) ?></title>
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
        .fw-finance__form input,
        .fw-finance__form select {
            padding: 0.5rem;
            font-size: 0.9rem;
        }
    </style>
</head>
<body class="fw-finance">
    <div class="fw-finance__container">
        <?php
        $finTitle = 'New Fixed Asset';
        $finBack = '/finances/fa/';
        $finCompanyName = $companyName;
        $finFirstName = $firstName;
        include __DIR__ . '/../partials/header.php';
        ?>
        <main class="fw-finance__main">
            <form id="assetForm" class="fw-finance__form" onsubmit="return false;">
                <label>
                    Asset Name
                    <input type="text" id="assetName" required>
                </label>
                <label>
                    Category (optional)
                    <input type="text" id="category">
                </label>
                <label>
                    Purchase Date
                    <input type="date" id="purchaseDate" value="<?= date('Y-m-d') ?>" required>
                </label>
                <label>
                    Purchase Cost (R)
                    <input type="number" id="purchaseCost" step="0.01" min="0" required>
                </label>
                <label>
                    Salvage Value (R)
                    <input type="number" id="salvageValue" step="0.01" min="0" value="0">
                </label>
                <label>
                    Useful Life (months)
                    <input type="number" id="usefulLife" min="1" step="1" required>
                </label>
                <label>
                    Depreciation Method
                    <select id="deprMethod" required>
                        <option value="straight_line">Straight Line</option>
                        <option value="declining_balance">Declining Balance</option>
                    </select>
                </label>
                <label>
                    Asset Account
                    <select id="assetAccount" required>
                        <option value="">Select account</option>
                        <?php foreach ($assetAccounts as $a): ?>
                            <option value="<?= (int)$a['account_id'] ?>">
                                <?= htmlspecialchars($a['account_code'] . ' - ' . $a['account_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Depreciation Expense Account
                    <select id="expenseAccount" required>
                        <option value="">Select account</option>
                        <?php foreach ($expenseAccounts as $a): ?>
                            <option value="<?= (int)$a['account_id'] ?>">
                                <?= htmlspecialchars($a['account_code'] . ' - ' . $a['account_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Accumulated Depreciation Account
                    <select id="accumAccount" required>
                        <option value="">Select account</option>
                        <?php foreach ($assetAccounts as $a): ?>
                            <option value="<?= (int)$a['account_id'] ?>">
                                <?= htmlspecialchars($a['account_code'] . ' - ' . $a['account_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="fw-finance__btn fw-finance__btn--primary">Save Asset</button>
                <div id="formMessage"></div>
            </form>
        </main>
        <footer class="fw-finance__footer">
            <span>New Fixed Asset v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>
    </div>
<script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
<script>
document.getElementById('assetForm').addEventListener('submit', function() {
    var payload = {
        asset_name: document.getElementById('assetName').value.trim(),
        category: document.getElementById('category').value.trim(),
        purchase_date: document.getElementById('purchaseDate').value,
        purchase_cost: parseFloat(document.getElementById('purchaseCost').value || 0),
        salvage_value: parseFloat(document.getElementById('salvageValue').value || 0),
        useful_life_months: parseInt(document.getElementById('usefulLife').value, 10),
        depreciation_method: document.getElementById('deprMethod').value,
        asset_account_id: parseInt(document.getElementById('assetAccount').value, 10),
        depreciation_expense_account_id: parseInt(document.getElementById('expenseAccount').value, 10),
        accumulated_depreciation_account_id: parseInt(document.getElementById('accumAccount').value, 10)
    };
    // Basic validation
    if (!payload.asset_name || !payload.purchase_date || isNaN(payload.purchase_cost) || isNaN(payload.useful_life_months) || !payload.depreciation_method || !payload.asset_account_id || !payload.depreciation_expense_account_id || !payload.accumulated_depreciation_account_id) {
        document.getElementById('formMessage').innerHTML = '<div class="fw-finance__alert fw-finance__alert--error">Please fill in all required fields correctly.</div>';
        return;
    }
    fetch('/finances/fa/api/asset_create.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
        body: JSON.stringify(payload)
    }).then(function(resp) { return resp.json(); }).then(function(data) {
        if (data.success) {
            document.getElementById('formMessage').innerHTML = '<div class="fw-finance__alert fw-finance__alert--success">Asset created successfully.</div>';
            // Redirect back to asset list after short delay
            setTimeout(function() { window.location.href = '/finances/fa/'; }, 1500);
        } else {
            document.getElementById('formMessage').innerHTML = '<div class="fw-finance__alert fw-finance__alert--error">' + (data.message || 'Error creating asset') + '</div>';
        }
    }).catch(function(err) {
        document.getElementById('formMessage').innerHTML = '<div class="fw-finance__alert fw-finance__alert--error">' + err.message + '</div>';
    });
});
</script>
</body>
</html>