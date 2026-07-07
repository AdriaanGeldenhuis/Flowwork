<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ap/bill_view.php – View a supplier bill
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
requireRoles(['viewer','bookkeeper','admin']);

define('ASSET_VERSION', FIN_ASSET_VERSION);

require_once __DIR__ . '/../lib/Csrf.php';
$csrfToken = Csrf::token();

$companyId = (int)$_SESSION['company_id'];
$userId    = (int)$_SESSION['user_id'];
$billId    = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$billId) {
    echo 'Missing bill id';
    exit;
}

// Fetch bill header
$stmt = $DB->prepare(
    "SELECT b.*, c.name AS supplier_name, c.email AS supplier_email
     FROM ap_bills b
     LEFT JOIN crm_accounts c ON b.supplier_id = c.id
     WHERE b.id = ? AND b.company_id = ?"
);
$stmt->execute([$billId, $companyId]);
$bill = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$bill) {
    echo 'Bill not found';
    exit;
}
// Fetch bill lines
$stmt = $DB->prepare("SELECT * FROM ap_bill_lines WHERE bill_id = ? ORDER BY sort_order");
$stmt->execute([$billId]);
$lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Fetch payments and credits allocations to compute paid, credited and balance
$stmt = $DB->prepare(
    "SELECT COALESCE(SUM(amount),0) FROM ap_payment_allocations WHERE bill_id = ?"
);
$stmt->execute([$billId]);
$paid = floatval($stmt->fetchColumn());
$stmt = $DB->prepare(
    "SELECT COALESCE(SUM(amount),0) FROM vendor_credit_allocations WHERE bill_id = ?"
);
$stmt->execute([$billId]);
$credited = floatval($stmt->fetchColumn());
$balance = floatval($bill['total']) - ($paid + $credited);
// Determine user and company names for greeting
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
    <title>View Bill – <?= htmlspecialchars($companyName) ?></title>
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
    <style>
        .bill-summary {
            margin-top: 1rem;
        }
        .bill-summary th, .bill-summary td {
            padding: 0.5rem;
            text-align: left;
        }
        .bill-lines {
            margin-top: 1rem;
            width: 100%;
            border-collapse: collapse;
        }
        .bill-lines th, .bill-lines td {
            padding: 0.5rem;
            border-bottom: 1px solid var(--fw-border);
        }
        .bill-lines th {
            background: var(--fw-bg-secondary);
        }
        .bill-lines td.amount {
            text-align: right;
        }
    </style>
</head>
<body class="fw-finance">
    <div class="fw-finance__container">
        <?php
        $finTitle = 'Bill Detail';
        $finBack = '/finances/ap/';
        $finCompanyName = $companyName;
        $finFirstName = $firstName;
        include __DIR__ . '/../partials/header.php';
        ?>
        <main class="fw-finance__main">
            <div class="fw-finance__toolbar">
                <?php if (!in_array($bill['status'], ['posted', 'paid'])): ?>
                <button class="fw-finance__btn fw-finance__btn--primary" id="postBtn">Post to GL</button>
                <?php endif; ?>
                <?php if ($balance > 0): ?>
                <a href="/finances/ap/payment_new.php?supplier_id=<?= (int)$bill['supplier_id'] ?>&bill_id=<?= $billId ?>" class="fw-finance__btn">Record Payment</a>
                <?php endif; ?>
                <a href="/finances/ap/vendor_credit_new.php?supplier_id=<?= (int)$bill['supplier_id'] ?>&bill_id=<?= $billId ?>" class="fw-finance__btn">New Vendor Credit</a>
            </div>
            <h2>Bill: <?= htmlspecialchars($bill['vendor_invoice_number'] ?: ('BILL'.$billId)) ?></h2>
            <table class="bill-summary">
                <tr><th>Supplier:</th><td><?= htmlspecialchars($bill['supplier_name']) ?></td></tr>
                <tr><th>Issue Date:</th><td><?= htmlspecialchars($bill['issue_date']) ?></td></tr>
                <tr><th>Due Date:</th><td><?= htmlspecialchars($bill['due_date']) ?></td></tr>
                <tr><th>Status:</th><td><?= htmlspecialchars($bill['status']) ?></td></tr>
                <tr><th>Total:</th><td>R <?= number_format($bill['total'], 2) ?></td></tr>
                <tr><th>Paid:</th><td>R <?= number_format($paid, 2) ?></td></tr>
                <tr><th>Credited:</th><td>R <?= number_format($credited, 2) ?></td></tr>
                <tr><th>Balance:</th><td>R <?= number_format($balance, 2) ?></td></tr>
            </table>
            <h3>Lines</h3>
            <table class="bill-lines">
                <thead>
                    <tr><th>Description</th><th>Qty</th><th>Unit</th><th>Unit Price</th><th>Discount</th><th>Tax %</th><th class="amount">Line Total</th></tr>
                </thead>
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
        </main>
        <footer class="fw-finance__footer">
            <span>Bill Detail v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>
    </div>
<script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
<?php if (!in_array($bill['status'], ['posted', 'paid'])): ?>
<script>
document.getElementById('postBtn').addEventListener('click', async function() {
    if (!confirm('Post this bill to the general ledger?')) return;
    var hdrs = { 'Content-Type': 'application/json' };
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) hdrs['X-CSRF-Token'] = csrfMeta.content;
    const res = await fetch('/finances/ap/api/bill_post.php', {
        method: 'POST',
        headers: hdrs,
        body: JSON.stringify({ bill_id: <?= $billId ?> })
    });
    const result = await res.json();
    if (result.ok) {
        alert('Bill posted successfully');
        window.location.reload();
    } else {
        alert('Error: ' + (result.error || 'Failed to post'));
    }
});
</script>
<?php endif; ?>
</body>
</html>