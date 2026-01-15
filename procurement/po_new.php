<?php
// /procurement/po_new.php – Create a new purchase order
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-10-07-PO-NEW');

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);

// Fetch user and company names
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user  = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

// Fetch suppliers (CRM accounts of type supplier)
$stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND type = 'supplier' AND status = 'active' ORDER BY name");
$stmt->execute([$companyId]);
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch expense accounts for gl_account selection (optional for PO)
$stmt = $DB->prepare("SELECT account_id, account_code, account_name FROM gl_accounts WHERE company_id = ? AND is_active = 1 AND account_type = 'expense' ORDER BY account_code");
$stmt->execute([$companyId]);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Purchase Order – <?php echo htmlspecialchars($companyName); ?></title>
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?php echo ASSET_VERSION; ?>">
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
        table.po-lines {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        table.po-lines th, table.po-lines td {
            border: 1px solid var(--fw-border);
            padding: 0.5rem;
        }
        table.po-lines th {
            background: var(--fw-bg-secondary);
        }
        table.po-lines input, table.po-lines select {
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
                    <div class="fw-finance__company-name"><?php echo htmlspecialchars($companyName); ?></div>
                    <div class="fw-finance__app-name">New Purchase Order</div>
                </div>
            </div>
            <div class="fw-finance__greeting">
                Hello, <span class="fw-finance__greeting-name"><?php echo htmlspecialchars($firstName); ?></span>
            </div>
            <div class="fw-finance__controls">
                <a href="/procurement/po_list.php" class="fw-finance__back-btn" title="Back to PO List">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
            </div>
        </header>
        <div class="fw-finance__main">
            <form id="poForm" class="fw-finance__form" onsubmit="return false;">
                <label>
                    Supplier
                    <select id="supplierId" required>
                        <option value="">Select Supplier</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    PO Number
                    <input type="text" id="poNumber" placeholder="PO-YYYY-0001" required>
                </label>
                <label>
                    Notes
                    <textarea id="notes" rows="2"></textarea>
                </label>
                <!-- Line items table -->
                <table class="po-lines" id="linesTable">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th style="width:8%">Qty</th>
                            <th style="width:10%">Unit</th>
                            <th style="width:12%">Unit Price</th>
                            <th style="width:10%">Tax %</th>
                            <th style="width:18%">Account</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="linesBody">
                    </tbody>
                </table>
                <button type="button" class="fw-finance__btn" id="addLineBtn">+ Add Line</button>
                <button type="submit" class="fw-finance__btn fw-finance__btn--primary">Save PO</button>
                <div id="formMessage"></div>
            </form>
        </div>
        <footer class="fw-finance__footer">
            <span>Procurement New PO v<?php echo ASSET_VERSION; ?></span>
        </footer>
    </div>
</main>
<script src="/finances/assets/finance.js?v=<?php echo ASSET_VERSION; ?>"></script>
<script>
// Accounts list for dropdown
var accounts = <?php echo json_encode(array_map(function($a) {
    return [
        'id' => $a['account_id'],
        'code' => $a['account_code'],
        'name' => $a['account_name']
    ];
}, $accounts)); ?>;

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

function addLineRow(desc, qty, unit, price, taxRate, accountId) {
    var tbody = document.getElementById('linesBody');
    var tr = document.createElement('tr');
    tr.innerHTML = '' +
        '<td><input type="text" class="desc" value="' + (desc || '') + '" required></td>' +
        '<td><input type="number" class="qty" min="0" step="0.01" value="' + (qty || 1) + '"></td>' +
        '<td><input type="text" class="unit" value="' + (unit || 'ea') + '"></td>' +
        '<td><input type="number" class="price" min="0" step="0.01" value="' + (price || 0) + '"></td>' +
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

// Add initial row on load
document.addEventListener('DOMContentLoaded', function() {
    addLineRow();
});

// Handle form submission
document.getElementById('poForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    var supplierId = document.getElementById('supplierId').value;
    var poNumber  = document.getElementById('poNumber').value.trim();
    var notes     = document.getElementById('notes').value.trim();
    var rows      = document.querySelectorAll('#linesBody tr');
    if (!supplierId || !poNumber || rows.length === 0) {
        showMessage('formMessage', 'Please complete required fields and add at least one line', 'error');
        return;
    }
    var lines = [];
    rows.forEach(function(row) {
        var desc    = row.querySelector('.desc').value.trim();
        var qty     = parseFloat(row.querySelector('.qty').value) || 1;
        var unit    = row.querySelector('.unit').value.trim() || 'ea';
        var price   = parseFloat(row.querySelector('.price').value) || 0;
        var taxRate = parseFloat(row.querySelector('.taxRate').value) || 0;
        var glAcc   = row.querySelector('.account').value;
        lines.push({
            description: desc,
            qty: qty,
            unit: unit,
            unit_price: price,
            tax_rate: taxRate,
            gl_account_id: glAcc
        });
    });
    // Compute totals
    var subtotal = 0;
    var taxTotal = 0;
    lines.forEach(function(l) {
        var net = l.qty * l.unit_price;
        var vat = (l.tax_rate > 0) ? net * (l.tax_rate / 100) : 0;
        subtotal += net;
        taxTotal += vat;
    });
    var total = subtotal + taxTotal;
    var data = {
        header: {
            supplier_id: supplierId,
            po_number: poNumber,
            subtotal: subtotal,
            tax: taxTotal,
            total: total,
            notes: notes
        },
        lines: lines
    };
    try {
        var res = await fetch('/procurement/ajax/po.create.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        var result = await res.json();
        if (result.ok) {
            showMessage('formMessage', 'Purchase order created successfully', 'success');
            setTimeout(function() {
                window.location.href = '/procurement/po_view.php?id=' + result.po_id;
            }, 1500);
        } else {
            showMessage('formMessage', result.error || 'Failed to create purchase order', 'error');
        }
    } catch (err) {
        showMessage('formMessage', 'An error occurred while creating the purchase order', 'error');
    }
});
</script>
</body>
</html>