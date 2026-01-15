<?php
// Supplier portal entry point. Allows a supplier to view their purchase
// orders and goods received notes, as well as upload compliance
// documentation. Access is controlled via a deterministic token based
// on the supplier CRM account ID. To access this page, the URL must
// include parameters sid=<ACCOUNT_ID>&token=<TOKEN>.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../functions.php';

// Retrieve parameters
$sid   = isset($_GET['sid']) ? (int)$_GET['sid'] : 0;
$token = $_GET['token'] ?? '';

// Validate token
if ($sid <= 0 || empty($token) || !verifyPortalToken($sid, $token)) {
    http_response_code(403);
    echo '<h1>Access Denied</h1><p>Invalid portal link.</p>';
    exit;
}

// Fetch supplier account
$stmt = $DB->prepare("SELECT company_id, name FROM crm_accounts WHERE id = ? AND type = 'supplier'");
$stmt->execute([$sid]);
$supplier = $stmt->fetch();
if (!$supplier) {
    http_response_code(404);
    echo '<h1>Supplier not found</h1>';
    exit;
}

$companyId = (int)$supplier['company_id'];

// Fetch purchase orders for this supplier
$stmt = $DB->prepare("SELECT id, po_number, status, total, created_at FROM purchase_orders WHERE company_id = ? AND supplier_id = ? ORDER BY created_at DESC");
$stmt->execute([$companyId, $sid]);
$purchaseOrders = $stmt->fetchAll();

// Fetch goods received notes associated with these POs
// We'll gather GRNs by PO ID for summary
$poIds = array_column($purchaseOrders, 'id');
$grnSummary = [];
if (!empty($poIds)) {
    $placeholders = implode(',', array_fill(0, count($poIds), '?'));
    $inParams = $poIds;
    $stmt = $DB->prepare("SELECT grn.id, grn.po_id, grn.total, grn.received_date, grn.status FROM goods_received_notes grn WHERE grn.po_id IN ($placeholders) AND grn.company_id = ?");
    $inParams[] = $companyId;
    $stmt->execute($inParams);
    $grns = $stmt->fetchAll();
    foreach ($grns as $grn) {
        $poId = $grn['po_id'];
        if (!isset($grnSummary[$poId])) {
            $grnSummary[$poId] = [];
        }
        $grnSummary[$poId][] = $grn;
    }
}

// Fetch compliance docs for this supplier
$stmt = $DB->prepare("SELECT d.id, d.type_id, d.reference_no, d.issue_date, d.expiry_date, d.status, d.file_path, t.name AS type_name FROM crm_compliance_docs d JOIN crm_compliance_types t ON d.type_id = t.id WHERE d.company_id = ? AND d.account_id = ? ORDER BY d.created_at DESC");
$stmt->execute([$companyId, $sid]);
$complianceDocs = $stmt->fetchAll();

// Fetch compliance types for this company to populate upload form
$stmt = $DB->prepare("SELECT id, code, name FROM crm_compliance_types WHERE company_id = ? ORDER BY name");
$stmt->execute([$companyId]);
$complianceTypes = $stmt->fetchAll();

// Determine page title
$pageTitle = htmlspecialchars($supplier['name']) . ' – Supplier Portal';

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
        h1 { margin-top: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 8px 12px; border: 1px solid #e5e7eb; text-align: left; }
        th { background: #f3f4f6; }
        .section { margin-bottom: 40px; }
        .btn {
            display: inline-block;
            padding: 6px 12px;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            border-radius: 4px;
            margin-right: 6px;
            color: #fff;
        }
        .btn-view { background: #3b82f6; }
        .btn-upload { background: #10b981; }
        form.upload-form {
            margin-top: 12px;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            background: #fff;
        }
        form.upload-form label { display: block; margin-bottom: 6px; font-weight: 500; }
        form.upload-form input[type="text"], form.upload-form input[type="date"], form.upload-form select {
            width: 100%;
            padding: 6px;
            margin-bottom: 10px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 0.875rem;
        }
        form.upload-form input[type="file"] { margin-bottom: 10px; }
        form.upload-form button {
            background: #2563eb;
            color: #fff;
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <h1><?= htmlspecialchars($supplier['name']) ?> – Supplier Portal</h1>
    <p>Welcome to your supplier portal. Here you can track your purchase orders, goods received notes, and upload compliance documents.</p>
    <?php if (isset($_GET['uploaded']) && $_GET['uploaded'] == '1'): ?>
        <p style="background:#d1fae5;color:#065f46;padding:8px 12px;border:1px solid #a7f3d0;border-radius:4px;">Your document has been uploaded successfully.</p>
    <?php endif; ?>

    <div class="section">
        <h2>Purchase Orders</h2>
        <?php if (empty($purchaseOrders)): ?>
            <p>No purchase orders found.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>PO #</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Total</th>
                        <th>GRNs Received</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($purchaseOrders as $po): ?>
                        <tr>
                            <td><?= htmlspecialchars($po['po_number']) ?></td>
                            <td><?= htmlspecialchars(date('Y-m-d', strtotime($po['created_at']))) ?></td>
                            <td><?= htmlspecialchars(ucfirst($po['status'])) ?></td>
                            <td>R <?= number_format($po['total'], 2) ?></td>
                            <td>
                                <?php
                                $poId = $po['id'];
                                $grns = $grnSummary[$poId] ?? [];
                                if (empty($grns)) {
                                    echo '0';
                                } else {
                                    echo count($grns);
                                    echo ' (';
                                    $labels = [];
                                    foreach ($grns as $g) {
                                        $labels[] = htmlspecialchars(date('Y-m-d', strtotime($g['received_date'])));
                                    }
                                    echo implode(', ', $labels);
                                    echo ')';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>Compliance Documents</h2>
        <?php if (empty($complianceDocs)): ?>
            <p>No compliance documents on file.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Reference</th>
                        <th>Issue Date</th>
                        <th>Expiry Date</th>
                        <th>Status</th>
                        <th>File</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($complianceDocs as $doc): ?>
                        <tr>
                            <td><?= htmlspecialchars($doc['type_name']) ?></td>
                            <td><?= htmlspecialchars($doc['reference_no'] ?? '') ?></td>
                            <td><?= htmlspecialchars($doc['issue_date']) ?></td>
                            <td><?= htmlspecialchars($doc['expiry_date']) ?></td>
                            <td><?= htmlspecialchars(ucfirst($doc['status'])) ?></td>
                            <td>
                                <?php if (!empty($doc['file_path'])): ?>
                                    <a href="<?= htmlspecialchars($doc['file_path']) ?>" class="btn btn-view" target="_blank">View</a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>Upload Compliance Document</h2>
        <form class="upload-form" action="upload_compliance.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="sid" value="<?= $sid ?>">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <label for="type_id">Document Type</label>
            <select name="type_id" id="type_id" required>
                <option value="">Select type...</option>
                <?php foreach ($complianceTypes as $type): ?>
                    <option value="<?= $type['id'] ?>">
                        <?= htmlspecialchars($type['name']) ?> (<?= htmlspecialchars($type['code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <label for="reference_no">Reference Number</label>
            <input type="text" name="reference_no" id="reference_no" placeholder="e.g. COC‑2025‑001" required>
            <label for="issue_date">Issue Date</label>
            <input type="date" name="issue_date" id="issue_date" required>
            <label for="expiry_date">Expiry Date</label>
            <input type="date" name="expiry_date" id="expiry_date" required>
            <label for="file">Document File (PDF or Image)</label>
            <input type="file" name="file" id="file" accept="application/pdf,image/*" required>
            <button type="submit">Upload Document</button>
        </form>
    </div>
</body>
</html>