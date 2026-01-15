<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ap/vendor_credit_new.php – Create a vendor credit note
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
requireRoles(['bookkeeper','admin']);

define('ASSET_VERSION', '2025-01-21-AP-VCNEW');

$companyId = (int)$_SESSION['company_id'];
$userId    = (int)$_SESSION['user_id'];

// Preselect supplier or bill
$preSupplierId = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
$preBillId     = isset($_GET['bill_id']) ? (int)$_GET['bill_id'] : 0;

// Fetch user and company names
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user  = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

// Fetch suppliers
$stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND type = 'supplier' AND status = 'active' ORDER BY name");
$stmt->execute([$companyId]);
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch GL expense accounts (and inventory/asset) for lines
$stmt = $DB->prepare("SELECT account_id, account_code, account_name FROM gl_accounts WHERE company_id = ? AND is_active = 1 AND account_type IN ('expense','asset','inventory') ORDER BY account_code");
$stmt->execute([$companyId]);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Vendor Credit – <?= htmlspecialchars($companyName) ?></title>
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
        table.credit-lines {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        table.credit-lines th,
        table.credit-lines td {
            border: 1px solid var(--fw-border);
            padding: 0.5rem;
        }
        table.credit-lines th {
            background: var(--fw-bg-secondary);
        }
        table.credit-lines input,
        table.credit-lines select {
            width: 100%;
            box-sizing: border-box;
        }
    </style>
</head>
<body>
<main class="fw-finance">
    <div class="fw-finance__container">
        <header class="fw-finance__header">
            <div class="fw-finance__brand">
                <div class="fw-finance__logo-tile">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="fw-finance__brand-text">
                    <div class="fw-finance__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-finance__app-name">New Vendor Credit</div>
                </div>
            </div>
            <div class="fw-finance__greeting">
                Hello, <span class="fw-finance__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>
            <div class="fw-finance__controls">
                <a href="/finances/ap/bills_list.php" class="fw-finance__back-btn" title="Back to Bills">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
            </div>
        </header>
        <div class="fw-finance__main">
            <form id="creditForm" class="fw-finance__form" onsubmit="return false;">
                <label>
                    Supplier
                    <select id="supplierId" required>
                        <option value="">Select Supplier</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?php if ($preSupplierId && $preSupplierId == $s['id']) echo 'selected'; ?>><?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Credit Number
                    <input type="text" id="creditNumber" required>
                </label>
                <label>
                    Issue Date
                    <input type="date" id="issueDate" value="<?= date('Y-m-d') ?>" required>
                </label>
                <label>
                    Notes
                    <textarea id="notes" rows="2"></textarea>
                </label>
                <!-- Lines table -->
                <table class="credit-lines" id="linesTable">
                    <thead>
                        <tr><th>Description</th><th style="width:8%">Qty</th><th style="width:10%">Unit</th><th style="width:12%">Unit Price</th><th style="width:10%">Discount</th><th style="width:10%">Tax %</th><th style="width:18%">Account</th><th></th></tr>
                    </thead>
                    <tbody id="linesBody"></tbody>
                </table>
                <button type="button" class="fw-finance__btn" id="addLineBtn">+ Add Line</button>
                <button type="submit" class="fw-finance__btn fw-finance__btn--primary">Save Credit</button>
                <div id="formMessage"></div>
            </form>
        </div>
        <footer class="fw-finance__footer">
            <span>Finance AP Vendor Credit v<?= ASSET_VERSION ?></span>
        </footer>
    </div>
</main>
<script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
<script>
// Accounts array for select options
var accounts = <?php echo json_encode(array_map(function($a) { return [
    'id'   => $a['account_id'],
    'code' => $a['account_code'],
    'name' => $a['account_name'],
]; }, $accounts)); ?>;

function buildAccountSelect(selectedId) {
    var html = '<select class="account">';
    html += '<option value="">Select</option>';
    accounts.forEach(function(a) {
        var sel = (selectedId && Number(selectedId) === Number(a.id)) ? ' selected' : '';
        html += '<option value="' + a.id + '"' + sel + '>' + a.code + ' - ' + a.name + '</option>';
    });
    html += '</select>';
    return html;
}

function addLineRow(desc, qty, unit, price, discount, taxRate, accountId) {
    var tbody = document.getElementById('linesBody');
    var tr = document.createElement('tr');
    tr.innerHTML = '' +
        '<td><input type="text" class="desc" value="' + (desc || '') + '" required></td>' +
        '<td><input type="number" class="qty" min="0" step="0.01" value="' + (qty || 1) + '"></td>' +
        '<td><input type="text" class="unit" value="' + (unit || 'ea') + '"></td>' +
        '<td><input type="number" class="price" min="0" step="0.01" value="' + (price || 0) + '"></td>' +
        '<td><input type="number" class="discount" min="0" step="0.01" value="' + (discount || 0) + '"></td>' +
        '<td><input type="number" class="taxRate" min="0" step="0.01" value="' + (taxRate !== undefined ? taxRate : 15) + '"></td>' +
        '<td>' + buildAccountSelect(accountId) + '</td>' +
        '<td><button type="button" class="removeLineBtn">×</button></td>';
    tbody.appendChild(tr);
}

document.getElementById('addLineBtn').addEventListener('click', function() {
    addLineRow();
});

document.getElementById('linesTable').addEventListener('click', function(e) {
    if (e.target && e.target.classList.contains('removeLineBtn')) {
        e.target.closest('tr').remove();
    }
});
// Add initial row
document.addEventListener('DOMContentLoaded', function() {
    addLineRow();
});

document.getElementById('creditForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    var supplierId   = document.getElementById('supplierId').value;
    var creditNumber = document.getElementById('creditNumber').value.trim();
    var issueDate    = document.getElementById('issueDate').value;
    var notes        = document.getElementById('notes').value.trim();
    var rows         = document.querySelectorAll('#linesBody tr');
    if (!supplierId || !creditNumber || !issueDate || rows.length === 0) {
        showMessage('formMessage', 'Please complete required fields and add at least one line', 'error');
        return;
    }
    var lines = [];
    rows.forEach(function(row) {
        var desc     = row.querySelector('.desc').value.trim();
        var qty      = parseFloat(row.querySelector('.qty').value) || 1;
        var unit     = row.querySelector('.unit').value.trim() || 'ea';
        var price    = parseFloat(row.querySelector('.price').value) || 0;
        var discount = parseFloat(row.querySelector('.discount').value) || 0;
        var taxRate  = parseFloat(row.querySelector('.taxRate').value) || 0;
        var accId    = row.querySelector('.account').value;
        lines.push({
            description: desc,
            qty: qty,
            unit: unit,
            unit_price: price,
            discount: discount,
            tax_rate: taxRate,
            gl_account_id: accId
        });
    });
    // Compute totals for display but not used here
    var subtotal = 0;
    var taxTotal = 0;
    lines.forEach(function(l) {
        var net = (l.qty * l.unit_price) - l.discount;
        var vat = (l.tax_rate > 0) ? net * (l.tax_rate / 100) : 0;
        subtotal += net;
        taxTotal += vat;
    });
    var data = {
        header: {
            supplier_id: parseInt(supplierId),
            credit_number: creditNumber,
            issue_date: issueDate,
            notes: notes
        },
        lines: lines
    };
    try {
        const res = await fetch('/finances/ap/api/vendor_credit_create.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await res.json();
        if (!result.ok) {
            showMessage('formMessage', result.error || 'Failed to save credit', 'error');
            return;
        }
        showMessage('formMessage', 'Vendor credit created successfully', 'success');
        setTimeout(function() {
            window.location.href = '/finances/ap/vendor_credit_view.php?id=' + result.credit_id;
        }, 1500);
    } catch (err) {
        showMessage('formMessage', err.message, 'error');
    }
});
</script>
</body>
</html>