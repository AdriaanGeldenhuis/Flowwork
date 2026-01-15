<?php
// /finances/ar/statement.php – View customer statements
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-FIN-STMT');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

// Fetch user first name
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

// Fetch company name
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

// Fetch list of customers
$stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND type = 'customer' ORDER BY name");
$stmt->execute([$companyId]);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Statements – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
    <style>
        .fw-finance__form {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        .fw-finance__form label {
            display: flex;
            flex-direction: column;
            font-size: 0.875rem;
        }
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
        .fw-finance__table td.debit {
            color: #b91c1c;
            text-align: right;
        }
        .fw-finance__table td.credit {
            color: #166534;
            text-align: right;
        }
        .fw-finance__table td.balance {
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
                        <div class="fw-finance__company-name"><?= htmlspecialchars($companyName) ?></div>
                        <div class="fw-finance__app-name">Customer Statements</div>
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
                <!-- Statement generator form -->
                <form class="fw-finance__form" id="statementForm" onsubmit="return false;">
                    <label>
                        Customer
                        <select id="customerSelect" required>
                            <option value="">Select Customer</option>
                            <?php foreach ($customers as $cust): ?>
                                <option value="<?= (int)$cust['id'] ?>"><?= htmlspecialchars($cust['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        From
                        <input type="date" id="startDate" value="">
                    </label>
                    <label>
                        To
                        <input type="date" id="endDate" value="">
                    </label>
                    <button class="fw-finance__btn fw-finance__btn--primary" id="generateBtn">Generate</button>
                </form>
                <div id="statementResult">
                    <!-- Statement will render here -->
                </div>
            </div>
            <footer class="fw-finance__footer">
                <span>Finance AR Statements v<?= ASSET_VERSION ?></span>
            </footer>
        </div>
    </main>
    <script>
    document.getElementById('generateBtn').addEventListener('click', async function() {
        const custId   = document.getElementById('customerSelect').value;
        const start    = document.getElementById('startDate').value;
        const end      = document.getElementById('endDate').value;
        if (!custId) {
            alert('Please select a customer');
            return;
        }
        const params = new URLSearchParams();
        params.append('customer_id', custId);
        if (start) params.append('start_date', start);
        if (end) params.append('end_date', end);
        const container = document.getElementById('statementResult');
        container.innerHTML = '<div class="fw-finance__loading">Generating statement...</div>';
        try {
            const res = await fetch('/finances/ajax/ar_statement.php?' + params.toString());
            const data = await res.json();
            if (!data.ok) {
                container.innerHTML = '<div class="fw-finance__error">Error: ' + (data.error || 'Unknown error') + '</div>';
                return;
            }
            const opening = parseFloat(data.opening_balance || 0);
            const lines   = data.data || [];
            let html = '';
            html += '<div><strong>Opening Balance:</strong> R ' + opening.toFixed(2) + '</div>';
            if (lines.length === 0) {
                html += '<div>No transactions found for this period.</div>';
            } else {
                html += '<table class="fw-finance__table">';
                html += '<thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>Description</th><th class="debit">Debit</th><th class="credit">Credit</th><th class="balance">Balance</th></tr></thead>';
                html += '<tbody>';
                lines.forEach(function(row) {
                    html += '<tr>';
                    html += '<td>' + row.date + '</td>';
                    html += '<td>' + row.type + '</td>';
                    html += '<td>' + row.reference + '</td>';
                    html += '<td>' + row.description + '</td>';
                    html += '<td class="debit">' + (row.debit ? parseFloat(row.debit).toFixed(2) : '') + '</td>';
                    html += '<td class="credit">' + (row.credit ? parseFloat(row.credit).toFixed(2) : '') + '</td>';
                    html += '<td class="balance">' + parseFloat(row.balance).toFixed(2) + '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table>';
            }
            container.innerHTML = html;
        } catch (err) {
            container.innerHTML = '<div class="fw-finance__error">Network error: ' + err.message + '</div>';
        }
    });
    </script>
</body>
</html>