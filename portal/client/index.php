<?php
// Client portal entry point. Allows a customer to view all quotes and invoices
// associated with their CRM account via a simple token. The portal is read‑only
// except for links to accept/decline quotes and pay invoices through existing
// mechanisms (e.g. Yoco payment links). To access this page, a URL of the form
// /portal/client/index.php?cid=<ACCOUNT_ID>&token=<TOKEN> must be used.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../functions.php';

// Pull request parameters
$cid   = isset($_GET['cid']) ? (int)$_GET['cid'] : 0;
$token = $_GET['token'] ?? '';

// Basic validation
if ($cid <= 0 || empty($token) || !verifyPortalToken($cid, $token)) {
    http_response_code(403);
    echo '<h1>Access Denied</h1><p>Invalid portal link.</p>';
    exit;
}

// Fetch the CRM account
$stmt = $DB->prepare("SELECT company_id, name FROM crm_accounts WHERE id = ? AND type = 'customer'");
$stmt->execute([$cid]);
$account = $stmt->fetch();
if (!$account) {
    http_response_code(404);
    echo '<h1>Customer not found</h1>';
    exit;
}

$companyId = (int)$account['company_id'];

// Fetch quotes belonging to this customer
$stmt = $DB->prepare("SELECT id, quote_number, issue_date, expiry_date, status, total, public_token FROM quotes WHERE company_id = ? AND customer_id = ? ORDER BY issue_date DESC");
$stmt->execute([$companyId, $cid]);
$quotes = $stmt->fetchAll();

// Fetch invoices belonging to this customer
$stmt = $DB->prepare("SELECT id, invoice_number, issue_date, due_date, status, total, balance_due, pdf_path, yoco_payment_link FROM invoices WHERE company_id = ? AND customer_id = ? ORDER BY issue_date DESC");
$stmt->execute([$companyId, $cid]);
$invoices = $stmt->fetchAll();

// Build base URL for quote viewing
function quoteViewUrl($publicToken) {
    return '/qi/p/quote.php?token=' . urlencode($publicToken);
}

// Determine company name for the page title
$pageTitle = htmlspecialchars($account['name']) . ' – Client Portal';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="/assets/portal.css?v=2025-10-07">
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f9fafb;
            color: #111827;
        }
        h1 {
            margin-top: 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }
        th, td {
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            text-align: left;
        }
        th {
            background: #f3f4f6;
        }
        .section {
            margin-bottom: 40px;
        }
        a.btn {
            display: inline-block;
            padding: 6px 12px;
            margin-right: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            border-radius: 4px;
            color: #fff;
        }
        a.btn-view { background: #3b82f6; }
        a.btn-pay  { background: #10b981; }
        a.btn-pay.disabled {
            background: #d1d5db;
            pointer-events: none;
        }
    </style>
</head>
<body>
    <h1><?= htmlspecialchars($account['name']) ?> – Client Portal</h1>
    <p>Below are your recent quotes and invoices. For quotes, you can view the detailed document and accept or decline. For invoices, you can view the PDF and, if enabled, pay online.</p>

    <div class="section">
        <h2>Quotes</h2>
        <?php if (empty($quotes)): ?>
            <p>No quotes available.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Quote #</th>
                        <th>Date</th>
                        <th>Expires</th>
                        <th>Status</th>
                        <th>Total</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($quotes as $q): ?>
                        <tr>
                            <td><?= htmlspecialchars($q['quote_number']) ?></td>
                            <td><?= htmlspecialchars($q['issue_date']) ?></td>
                            <td><?= htmlspecialchars($q['expiry_date']) ?></td>
                            <td><?= htmlspecialchars(ucfirst($q['status'])) ?></td>
                            <td>R <?= number_format($q['total'], 2) ?></td>
                            <td>
                                <?php if ($q['public_token']): ?>
                                    <a href="<?= quoteViewUrl($q['public_token']) ?>" class="btn btn-view" target="_blank">View &amp; Respond</a>
                                <?php else: ?>
                                    <span style="color:#6b7280;">No link</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>Invoices</h2>
        <?php if (empty($invoices)): ?>
            <p>No invoices available.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Date</th>
                        <th>Due</th>
                        <th>Status</th>
                        <th>Total</th>
                        <th>Balance Due</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td><?= htmlspecialchars($inv['invoice_number']) ?></td>
                            <td><?= htmlspecialchars($inv['issue_date']) ?></td>
                            <td><?= htmlspecialchars($inv['due_date']) ?></td>
                            <td><?= htmlspecialchars(ucfirst($inv['status'])) ?></td>
                            <td>R <?= number_format($inv['total'], 2) ?></td>
                            <td>R <?= number_format($inv['balance_due'], 2) ?></td>
                            <td>
                                <?php if (!empty($inv['pdf_path'])): ?>
                                    <a href="<?= htmlspecialchars($inv['pdf_path']) ?>" class="btn btn-view" target="_blank">Download PDF</a>
                                <?php endif; ?>
                                <?php if (!empty($inv['yoco_payment_link']) && $inv['balance_due'] > 0): ?>
                                    <a href="<?= htmlspecialchars($inv['yoco_payment_link']) ?>" class="btn btn-pay" target="_blank">Pay Now</a>
                                <?php else: ?>
                                    <a class="btn btn-pay disabled">Paid</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>