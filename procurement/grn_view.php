<?php
// /procurement/grn_view.php – View a Goods Received Note and its lines
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-10-07-GRN-VIEW');

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
$grnId     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($grnId <= 0) {
    echo 'Missing GRN id';
    exit;
}

// Fetch GRN header with PO and supplier info
$stmt = $DB->prepare(
    "SELECT grn.*, po.po_number, acc.name AS supplier_name, acc.email AS supplier_email
     FROM goods_received_notes grn
     LEFT JOIN purchase_orders po ON po.id = grn.po_id
     LEFT JOIN crm_accounts acc ON acc.id = po.supplier_id
     WHERE grn.id = ? AND grn.company_id = ?"
);
$stmt->execute([$grnId, $companyId]);
$grn = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$grn) {
    echo 'Goods received note not found';
    exit;
}

// Fetch lines joined with PO lines for descriptions and pricing
$stmt = $DB->prepare(
    "SELECT gl.*, pol.description, pol.unit AS po_unit, pol.unit_price, pol.tax_rate
     FROM grn_lines gl
     LEFT JOIN purchase_order_lines pol ON pol.id = gl.po_line_id
     WHERE gl.grn_id = ?
     ORDER BY gl.id"
);
$stmt->execute([$grnId]);
$lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>View GRN – <?php echo htmlspecialchars($companyName); ?></title>
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?php echo ASSET_VERSION; ?>">
    <style>
        .grn-summary {
            margin-top: 1rem;
        }
        .grn-summary th, .grn-summary td {
            padding: 0.5rem;
            text-align: left;
        }
        .grn-lines {
            margin-top: 1rem;
            width: 100%;
            border-collapse: collapse;
        }
        .grn-lines th, .grn-lines td {
            padding: 0.5rem;
            border-bottom: 1px solid var(--fw-border);
        }
        .grn-lines th {
            background: var(--fw-bg-secondary);
        }
        .grn-lines td.amount {
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
                    <div class="fw-finance__app-name">GRN Detail</div>
                </div>
            </div>
            <div class="fw-finance__greeting">
                Hello, <span class="fw-finance__greeting-name"><?php echo htmlspecialchars($firstName); ?></span>
            </div>
            <div class="fw-finance__controls">
                <a href="/procurement/po_view.php?id=<?php echo (int)$grn['po_id']; ?>" class="fw-finance__btn" title="Back to PO">View PO</a>
                <a href="/procurement/po_list.php" class="fw-finance__back-btn" title="Back to PO List" style="margin-left:0.5rem;">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
            </div>
        </header>
        <div class="fw-finance__main">
            <h2>Goods Received Note: <?php echo htmlspecialchars($grn['grn_number']); ?></h2>
            <table class="grn-summary">
                <tr><th>PO #:</th><td><?php echo htmlspecialchars($grn['po_number']); ?></td></tr>
                <tr><th>Supplier:</th><td><?php echo htmlspecialchars($grn['supplier_name']); ?></td></tr>
                <tr><th>Email:</th><td><?php echo htmlspecialchars($grn['supplier_email']); ?></td></tr>
                <tr><th>Created At:</th><td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($grn['created_at']))); ?></td></tr>
                <tr><th>Status:</th><td><?php echo htmlspecialchars($grn['status']); ?></td></tr>
                <tr><th>Total:</th><td>R <?php echo number_format((float)$grn['total'], 2); ?></td></tr>
                <tr><th>Notes:</th><td><?php echo nl2br(htmlspecialchars($grn['notes'] ?? '')); ?></td></tr>
            </table>
            <h3>Lines</h3>
            <table class="grn-lines">
                <thead>
                    <tr><th>Description</th><th>Qty Received</th><th>Unit</th><th>Unit Price</th><th>Tax %</th><th class="amount">Line Total</th></tr>
                </thead>
                <tbody>
                <?php foreach ($lines as $ln): ?>
                    <?php
                        $qty  = (float)$ln['qty_received'];
                        $price = (float)$ln['unit_price'];
                        $taxRate = (float)$ln['tax_rate'];
                        $net = $qty * $price;
                        $vat = ($taxRate > 0) ? $net * ($taxRate / 100) : 0;
                        $lineTotal = $net + $vat;
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($ln['description']); ?></td>
                        <td><?php echo number_format($qty, 3); ?></td>
                        <td><?php echo htmlspecialchars($ln['po_unit']); ?></td>
                        <td class="amount"><?php echo number_format($price, 2); ?></td>
                        <td><?php echo htmlspecialchars($ln['tax_rate']); ?></td>
                        <td class="amount"><?php echo number_format($lineTotal, 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <footer class="fw-finance__footer">
            <span>Procurement GRN View v<?php echo ASSET_VERSION; ?></span>
        </footer>
    </div>
</main>
</body>
</html>