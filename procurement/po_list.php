<?php
// /procurement/po_list.php – List purchase orders for the current company
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-10-07-PO-LIST');

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);

// Fetch user and company names for greeting
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user  = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

// Fetch purchase orders for this company
$stmt = $DB->prepare(
    "SELECT po.id, po.po_number, po.total, po.status, po.created_at, acc.name AS supplier_name
     FROM purchase_orders po
     LEFT JOIN crm_accounts acc ON acc.id = po.supplier_id
     WHERE po.company_id = ?
     ORDER BY po.id DESC"
);
$stmt->execute([$companyId]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Orders – <?php echo htmlspecialchars($companyName); ?></title>
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?php echo ASSET_VERSION; ?>">
    <style>
        .fw-finance__table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        .fw-finance__table th,
        .fw-finance__table td {
            padding: 0.5rem;
            border-bottom: 1px solid var(--fw-border);
            text-align: left;
        }
        .fw-finance__table th {
            background: var(--fw-bg-secondary);
            font-weight: bold;
        }
        .fw-finance__table td.amount {
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
                    <div class="fw-finance__app-name">Purchase Orders</div>
                </div>
            </div>
            <div class="fw-finance__greeting">
                Hello, <span class="fw-finance__greeting-name"><?php echo htmlspecialchars($firstName); ?></span>
            </div>
            <div class="fw-finance__controls">
                <a href="/finances/" class="fw-finance__back-btn" title="Back to Finance">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
                <a href="/procurement/po_new.php" class="fw-finance__btn fw-finance__btn--primary" style="margin-left:0.5rem;">+ New PO</a>
            </div>
        </header>
        <div class="fw-finance__main">
            <?php if (!$orders): ?>
                <div class="fw-finance__empty-state">No purchase orders found.</div>
            <?php else: ?>
                <table class="fw-finance__table">
                    <thead>
                        <tr>
                            <th>PO #</th>
                            <th>Supplier</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th class="amount">Total</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $po): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($po['po_number']); ?></td>
                                <td><?php echo htmlspecialchars($po['supplier_name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($po['created_at']))); ?></td>
                                <td><?php echo htmlspecialchars($po['status']); ?></td>
                                <td class="amount"><?php echo number_format((float)$po['total'], 2); ?></td>
                                <td><a href="/procurement/po_view.php?id=<?php echo (int)$po['id']; ?>">View</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <footer class="fw-finance__footer">
            <span>Procurement PO List v<?php echo ASSET_VERSION; ?></span>
        </footer>
    </div>
</main>
</body>
</html>