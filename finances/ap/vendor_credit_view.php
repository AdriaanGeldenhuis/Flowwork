<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ap/vendor_credit_view.php – View and apply a vendor credit note
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
requireRoles(['viewer','bookkeeper','admin']);

define('ASSET_VERSION', '2025-01-21-AP-VCVIEW');

$companyId = (int)$_SESSION['company_id'];
$userId    = (int)$_SESSION['user_id'];

$creditId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$creditId) {
    echo 'Missing credit id';
    exit;
}

// Fetch credit header
$stmt = $DB->prepare(
    "SELECT vc.*, c.name AS supplier_name\n"
    . "FROM vendor_credits vc\n"
    . "LEFT JOIN crm_accounts c ON vc.supplier_id = c.id\n"
    . "WHERE vc.id = ? AND vc.company_id = ?"
);
$stmt->execute([$creditId, $companyId]);
$credit = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$credit) {
    echo 'Vendor credit not found';
    exit;
}

// Fetch credit lines
$stmt = $DB->prepare("SELECT * FROM vendor_credit_lines WHERE credit_id = ? ORDER BY sort_order");
$stmt->execute([$creditId]);
$lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch existing allocations
$stmt = $DB->prepare(
    "SELECT vca.*, b.vendor_invoice_number, b.issue_date, b.due_date\n"
    . "FROM vendor_credit_allocations vca\n"
    . "LEFT JOIN ap_bills b ON vca.bill_id = b.id\n"
    . "WHERE vca.credit_id = ?"
);
$stmt->execute([$creditId]);
$allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Compute totals and remaining credit
$total     = floatval($credit['total']);
$allocTot  = 0.0;
foreach ($allocations as $al) {
    $allocTot += floatval($al['amount']);
}
$remaining = $total - $allocTot;

// Fetch user and company names
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user  = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Credit Detail – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
    <style>
        .summary-table {
            margin-top: 1rem;
        }
        .summary-table th, .summary-table td {
            padding: 0.5rem;
            text-align: left;
        }
        .lines-table {
            margin-top: 1rem;
            width: 100%;
            border-collapse: collapse;
        }
        .lines-table th, .lines-table td {
            padding: 0.5rem;
            border-bottom: 1px solid var(--fw-border);
        }
        .lines-table th {
            background: var(--fw-bg-secondary);
        }
        .lines-table td.amount {
            text-align: right;
        }
        .alloc-table {
            margin-top: 1rem;
            width: 100%;
            border-collapse: collapse;
        }
        .alloc-table th, .alloc-table td {
            padding: 0.5rem;
            border-bottom: 1px solid var(--fw-border);
        }
        .alloc-table th {
            background: var(--fw-bg-secondary);
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
                    <div class="fw-finance__app-name">Vendor Credit Detail</div>
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
            <h2>Vendor Credit: <?= htmlspecialchars($credit['credit_number']) ?></h2>
            <table class="summary-table">
                <tr><th>Supplier:</th><td><?= htmlspecialchars($credit['supplier_name']) ?></td></tr>
                <tr><th>Issue Date:</th><td><?= htmlspecialchars($credit['issue_date']) ?></td></tr>
                <tr><th>Status:</th><td><?= htmlspecialchars($credit['status']) ?></td></tr>
                <tr><th>Total:</th><td>R <?= number_format($total, 2) ?></td></tr>
                <tr><th>Allocated:</th><td>R <?= number_format($allocTot, 2) ?></td></tr>
                <tr><th>Remaining:</th><td>R <?= number_format($remaining, 2) ?></td></tr>
            </table>
            <h3>Lines</h3>
            <table class="lines-table">
                <thead><tr><th>Description</th><th>Qty</th><th>Unit</th><th>Unit Price</th><th>Discount</th><th>Tax %</th><th class="amount">Line Total</th></tr></thead>
                <tbody>
                <?php foreach ($lines as $ln): ?>
                    <?php
                        $net = ($ln['quantity'] * $ln['unit_price']) - $ln['discount'];
                        $vat = ($ln['tax_rate'] > 0) ? $net * ($ln['tax_rate']/100) : 0;
                        $lineTotal = $net + $vat;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($ln['item_description']) ?></td>
                        <td><?= htmlspecialchars($ln['quantity']) ?></td>
                        <td><?= htmlspecialchars($ln['unit']) ?></td>
                        <td class="amount"><?= number_format($ln['unit_price'], 2) ?></td>
                        <td class="amount"><?= number_format($ln['discount'], 2) ?></td>
                        <td><?= htmlspecialchars($ln['tax_rate']) ?></td>
                        <td class="amount"><?= number_format($lineTotal, 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <h3>Allocations</h3>
            <?php if (empty($allocations)): ?>
                <div class="fw-finance__empty-state">No allocations made yet.</div>
            <?php else: ?>
                <table class="alloc-table">
                    <thead><tr><th>Bill</th><th>Issue Date</th><th>Due Date</th><th class="amount">Amount Applied</th></tr></thead>
                    <tbody>
                    <?php foreach ($allocations as $a): ?>
                        <tr>
                            <td><?= htmlspecialchars($a['vendor_invoice_number'] ?: ('Bill ' . $a['bill_id'])) ?></td>
                            <td><?= htmlspecialchars($a['issue_date']) ?></td>
                            <td><?= htmlspecialchars($a['due_date']) ?></td>
                            <td class="amount"><?= number_format($a['amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <?php if ($remaining > 0 && $credit['status'] !== 'applied'): ?>
            <h3>Apply Credit</h3>
            <p>Distribute the remaining credit amount (R <?= number_format($remaining, 2) ?>) across open bills for this supplier.</p>
            <div id="applyContainer">
                <div class="fw-finance__loading">Loading bills...</div>
            </div>
            <button id="applyBtn" class="fw-finance__btn fw-finance__btn--primary" style="margin-top:1rem;">Apply Credit</button>
            <div id="applyMessage"></div>
            <?php endif; ?>
        </div>
        <footer class="fw-finance__footer">
            <span>Finance AP Vendor Credit View v<?= ASSET_VERSION ?></span>
        </footer>
    </div>
</main>
<script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
<?php if ($remaining > 0 && $credit['status'] !== 'applied'): ?>
<script>
const creditId   = <?= $creditId ?>;
const supplierId = <?= (int)$credit['supplier_id'] ?>;
const maxAvail   = <?= json_encode($remaining) ?>;

async function loadSupplierBills() {
    const container = document.getElementById('applyContainer');
    container.innerHTML = '<div class="fw-finance__loading">Loading bills...</div>';
    try {
        const res = await fetch('/finances/ap/api/bill_list.php');
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'Failed to load bills');
        const bills = json.data || [];
        const outstanding = bills.filter(b => Number(b.supplier_id) === Number(supplierId) && parseFloat(b.balance) > 0.0001);
        if (outstanding.length === 0) {
            container.innerHTML = '<div class="fw-finance__empty-state">No outstanding bills for this supplier.</div>';
            document.getElementById('applyBtn').style.display = 'none';
            return;
        }
        let html = '<table class="alloc-table">';
        html += '<thead><tr><th>Bill</th><th>Due Date</th><th style="text-align:right">Balance</th><th style="width:20%">Apply</th></tr></thead><tbody>';
        outstanding.forEach(b => {
            const bal = parseFloat(b.balance);
            html += '<tr>' +
                '<td>' + (b.vendor_invoice_number || 'Bill ' + b.id) + '</td>' +
                '<td>' + (b.due_date || '') + '</td>' +
                '<td style="text-align:right">' + bal.toFixed(2) + '</td>' +
                '<td><input type="number" class="applyAmt" data-bill-id="' + b.id + '" min="0" step="0.01" max="' + bal.toFixed(2) + '"></td>' +
                '</tr>';
        });
        html += '</tbody></table>';
        container.innerHTML = html;
    } catch (err) {
        container.innerHTML = '<div class="fw-finance__error">Error loading bills: ' + err.message + '</div>';
    }
}
document.addEventListener('DOMContentLoaded', loadSupplierBills);

document.getElementById('applyBtn').addEventListener('click', async function() {
    // Gather allocations
    const inputs = document.querySelectorAll('.applyAmt');
    let totalAlloc = 0;
    const allocs = [];
    inputs.forEach(inp => {
        const amt = parseFloat(inp.value);
        if (amt > 0) {
            totalAlloc += amt;
            allocs.push({ bill_id: parseInt(inp.getAttribute('data-bill-id')), amount: amt });
        }
    });
    if (allocs.length === 0) {
        showMessage('applyMessage', 'Please enter at least one allocation amount', 'error');
        return;
    }
    if (totalAlloc > maxAvail + 0.001) {
        showMessage('applyMessage', 'Allocation exceeds remaining credit', 'error');
        return;
    }
    const payload = { credit_id: creditId, allocations: allocs };
    try {
        const res = await fetch('/finances/ap/api/vendor_credit_post.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const result = await res.json();
        if (!result.ok) {
            showMessage('applyMessage', result.error || 'Failed to apply credit', 'error');
            return;
        }
        showMessage('applyMessage', 'Credit applied successfully', 'success');
        setTimeout(function() { window.location.reload(); }, 1500);
    } catch (err) {
        showMessage('applyMessage', err.message, 'error');
    }
});
</script>
<?php endif; ?>
</body>
</html>