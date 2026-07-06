<?php
// /finances/ar/reminders.php – List overdue invoices for reminder purposes
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
requireRoles(['viewer', 'bookkeeper', 'admin']);

define('ASSET_VERSION', FIN_ASSET_VERSION);

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

// Fetch user and company for greeting
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

// Determine today's date
$today = date('Y-m-d');

// Fetch overdue invoices: status 'overdue' OR due_date < today and not paid/cancelled
$stmt = $DB->prepare("SELECT i.id, i.invoice_number, i.due_date, i.balance_due, i.status, ca.name AS customer_name
    FROM invoices i
    LEFT JOIN crm_accounts ca ON i.customer_id = ca.id
    WHERE i.company_id = ?
      AND (i.status = 'overdue' OR (i.due_date < ? AND i.status NOT IN ('paid','cancelled')))
    ORDER BY i.due_date ASC, i.invoice_number ASC");
$stmt->execute([$companyId, $today]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Reminders – <?= htmlspecialchars($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
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
        .fw-finance__notice {
            margin-top: 1rem;
            font-size: 0.9rem;
            color: var(--fw-text-secondary);
        }
    </style>
</head>
<body class="fw-finance">
    <div class="fw-finance__container">
            <?php
            $finTitle = 'Payment Reminders';
            $finBack = '/finances/ar/';
            $finCompanyName = $companyName;
            $finFirstName = $firstName;
            include __DIR__ . '/../partials/header.php';
            ?>
            <main class="fw-finance__main">
                <h2>Overdue Invoices</h2>
                <?php if (empty($invoices)): ?>
                    <p>No overdue invoices at the moment. Great job!</p>
                <?php else: ?>
                    <table class="fw-finance__table">
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Customer</th>
                                <th>Due Date</th>
                                <th>Days Overdue</th>
                                <th>Balance Due</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoices as $inv): ?>
                                <?php
                                    $due    = $inv['due_date'];
                                    $days   = 0;
                                    if ($due) {
                                        $dueTs = strtotime($due);
                                        $days  = floor((strtotime($today) - $dueTs) / (60*60*24));
                                        if ($days < 0) $days = 0;
                                    }
                                ?>
                                <tr>
                                    <td><a href="/qi/invoice_view.php?id=<?= (int)$inv['id'] ?>" target="_blank"><?= htmlspecialchars($inv['invoice_number']) ?></a></td>
                                    <td><?= htmlspecialchars($inv['customer_name'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($inv['due_date']) ?></td>
                                    <td><?= $days ?></td>
                                    <td>R <?= number_format((float)$inv['balance_due'], 2) ?></td>
                                    <td><?= htmlspecialchars($inv['status']) ?></td>
                                    <td><a href="/qi/invoice_view.php?id=<?= (int)$inv['id'] ?>" target="_blank">Open</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="fw-finance__notice">To send a payment reminder, open each invoice and click "Send to Customer". Dedicated reminder emails will be available soon.</p>
                <?php endif; ?>
            </main>
            <footer class="fw-finance__footer">
                <span>Payment Reminders v<?= ASSET_VERSION ?></span>
                <span id="themeIndicator">Theme: Light</span>
            </footer>
    </div>
    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>