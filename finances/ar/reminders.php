<?php
// /finances/ar/reminders.php – List overdue invoices for reminder purposes
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-FIN-REM');

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
                        <div class="fw-finance__app-name">Payment Reminders</div>
                    </div>
                </div>
                <div class="fw-finance__greeting">
                    Hello, <span class="fw-finance__greeting-name"><?= htmlspecialchars($firstName) ?></span>
                </div>
                <div class="fw-finance__controls">
                    <a href="/finances/ar/" class="fw-finance__back-btn" title="Back to AR">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    <a href="/finances/" class="fw-finance__home-btn" title="Finance Home">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                </div>
            </header>
            <div class="fw-finance__main">
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
            </div>
            <footer class="fw-finance__footer">
                <span>Finance AR Reminders v<?= ASSET_VERSION ?></span>
            </footer>
        </div>
    </main>
</body>
</html>