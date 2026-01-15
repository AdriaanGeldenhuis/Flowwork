<?php
// /procurement/po_view.php – View a purchase order and its lines
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-10-07-PO-VIEW');

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
$poId      = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($poId <= 0) {
    echo 'Missing purchase order ID';
    exit;
}

// Fetch PO header
$stmt = $DB->prepare(
    "SELECT po.*, acc.name AS supplier_name, acc.email AS supplier_email
     FROM purchase_orders po
     LEFT JOIN crm_accounts acc ON acc.id = po.supplier_id
     WHERE po.id = ? AND po.company_id = ?"
);
$stmt->execute([$poId, $companyId]);
$po = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$po) {
    echo 'Purchase order not found';
    exit;
}

// Fetch PO lines
$stmt = $DB->prepare("SELECT * FROM purchase_order_lines WHERE po_id = ? ORDER BY id");
$stmt->execute([$poId]);
$lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Compute qty received per line
$stmt = $DB->prepare(
    "SELECT gl.po_line_id, SUM(gl.qty_received) AS qty_received
     FROM grn_lines gl
     JOIN goods_received_notes grn ON grn.id = gl.grn_id
     WHERE grn.po_id = ? AND grn.status != 'cancelled'
     GROUP BY gl.po_line_id"
);
$stmt->execute([$poId]);
$received = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $received[(int)$row['po_line_id']] = (float)$row['qty_received'];
}

// Determine if any quantity remaining for GRN
$hasRemaining = false;
foreach ($lines as $ln) {
    $orderQty = (float)$ln['qty'];
    $recQty   = $received[$ln['id']] ?? 0.0;
    if ($orderQty > $recQty) {
        $hasRemaining = true;
        break;
    }
}

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
    <title>View Purchase Order – <?php echo htmlspecialchars($companyName); ?></title>
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?php echo ASSET_VERSION; ?>">
    <style>
        .po-summary {
            margin-top: 1rem;
        }
        .po-summary th, .po-summary td {
            padding: 0.5rem;
            text-align: left;
        }
        .po-lines {
            margin-top: 1rem;
            width: 100%;
            border-collapse: collapse;
        }
        .po-lines th, .po-lines td {
            padding: 0.5rem;
            border-bottom: 1px solid var(--fw-border);
        }
        .po-lines th {
            background: var(--fw-bg-secondary);
        }
        .po-lines td.amount {
            text-align: right;
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
                    <div class="fw-finance__app-name">Purchase Order Detail</div>
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
                <?php if ($po['status'] !== 'cancelled' && $hasRemaining): ?>
                <a href="/procurement/grn_new.php?po_id=<?php echo (int)$poId; ?>" class="fw-finance__btn fw-finance__btn--primary" style="margin-left:0.5rem;">Create GRN</a>
                <?php endif; ?>
            </div>
        </header>
        <div class="fw-finance__main">
            <h2>Purchase Order: <?php echo htmlspecialchars($po['po_number']); ?></h2>
            <table class="po-summary">
                <tr><th>Supplier:</th><td><?php echo htmlspecialchars($po['supplier_name']); ?></td></tr>
                <tr><th>Email:</th><td><?php echo htmlspecialchars($po['supplier_email']); ?></td></tr>
                <tr><th>Created At:</th><td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($po['created_at']))); ?></td></tr>
                <tr><th>Status:</th><td><?php echo htmlspecialchars($po['status']); ?></td></tr>
                <tr><th>Total:</th><td>R <?php echo number_format((float)$po['total'], 2); ?></td></tr>
                <tr><th>Notes:</th><td><?php echo nl2br(htmlspecialchars($po['notes'] ?? '')); ?></td></tr>
            </table>
            <h3>Lines</h3>
            <table class="po-lines">
                <thead>
                    <tr><th>Description</th><th>Qty Ordered</th><th>Qty Received</th><th>Qty Remaining</th><th>Unit</th><th>Unit Price</th><th>Tax %</th><th class="amount">Line Total</th></tr>
                </thead>
                <tbody>
                <?php foreach ($lines as $ln): ?>
                    <?php
                        $orderQty = (float)$ln['qty'];
                        $recQty   = $received[$ln['id']] ?? 0.0;
                        $remain   = $orderQty - $recQty;
                        $net      = $orderQty * (float)$ln['unit_price'];
                        $vat      = ($ln['tax_rate'] > 0) ? $net * ($ln['tax_rate']/100) : 0;
                        $lineTotal = $net + $vat;
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($ln['description']); ?></td>
                        <td><?php echo number_format($orderQty, 3); ?></td>
                        <td><?php echo number_format($recQty, 3); ?></td>
                        <td><?php echo number_format($remain, 3); ?></td>
                        <td><?php echo htmlspecialchars($ln['unit']); ?></td>
                        <td class="amount"><?php echo number_format((float)$ln['unit_price'], 2); ?></td>
                        <td><?php echo htmlspecialchars($ln['tax_rate']); ?></td>
                        <td class="amount"><?php echo number_format($lineTotal, 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <footer class="fw-finance__footer">
            <span>Procurement PO View v<?php echo ASSET_VERSION; ?></span>
        </footer>
    </div>
</main>
</body>
</html>