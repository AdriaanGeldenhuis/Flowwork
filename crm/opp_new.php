<?php
// /crm/opp_new.php – Create a new sales opportunity
// Presents a form to collect basic information about an opportunity and
// submits it via AJAX to opportunity_create.php. Only users with
// appropriate roles can access this page.

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-10-07-CRM-8');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];
$role      = $_SESSION['role'] ?? 'viewer';

// Only allow admin or member to create opportunities
if (!in_array($role, ['admin', 'member'])) {
    header('Location: /crm/');
    exit;
}

// Fetch lists for dropdowns
// Accounts (customers)
$stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND type = 'customer' ORDER BY name ASC");
$stmt->execute([$companyId]);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Owners (users in company)
$stmt = $DB->prepare("SELECT id, first_name, last_name FROM users WHERE company_id = ? ORDER BY first_name ASC");
$stmt->execute([$companyId]);
$owners = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stage options
$stageOptions = ['prospect', 'qualification', 'proposal', 'negotiation', 'won', 'lost'];

// Fetch user and company names
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$firstName = $user['first_name'] ?? 'User';

$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);
$companyName = $company['name'] ?? 'Company';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Opportunity – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/crm/assets/crm.css?v=<?= ASSET_VERSION ?>">
    <link rel="stylesheet" href="/crm/opps/opps.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-crm fw-opps">
    <div class="fw-crm__container">
        <!-- Header -->
        <header class="fw-crm__header">
            <div class="fw-crm__brand">
                <div class="fw-crm__logo-tile">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="2"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="fw-crm__brand-text">
                    <div class="fw-crm__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-crm__app-name">CRM – New Opportunity</div>
                </div>
            </div>
            <div class="fw-crm__greeting">
                Hello, <span class="fw-crm__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>
            <div class="fw-crm__controls">
                <a href="/crm/opps_list.php" class="fw-crm__back-btn" title="Back to Opportunities">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
                <a href="/" class="fw-crm__home-btn" title="Home">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
            </div>
        </header>
        <!-- Main content -->
        <main class="fw-crm__main">
            <h1 class="fw-crm__title">Create Opportunity</h1>
            <form id="oppForm" class="fw-crm__form" method="post" action="/crm/ajax/opportunity_create.php">
                <div class="fw-crm__form-group">
                    <label for="account_id" class="fw-crm__label">Account</label>
                    <select name="account_id" id="account_id" class="fw-crm__input" required>
                        <option value="" disabled selected>Select customer</option>
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?= (int)$a['id'] ?>"><?= htmlspecialchars($a['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fw-crm__form-group">
                    <label for="title" class="fw-crm__label">Title</label>
                    <input type="text" name="title" id="title" class="fw-crm__input" required>
                </div>
                <div class="fw-crm__form-group">
                    <label for="amount" class="fw-crm__label">Amount (R)</label>
                    <input type="number" step="0.01" min="0" name="amount" id="amount" class="fw-crm__input" required>
                </div>
                <div class="fw-crm__form-group">
                    <label for="stage" class="fw-crm__label">Stage</label>
                    <select name="stage" id="stage" class="fw-crm__input" required>
                        <?php foreach ($stageOptions as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>" <?= $s === 'prospect' ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fw-crm__form-group">
                    <label for="probability" class="fw-crm__label">Probability (%)</label>
                    <input type="number" step="1" min="0" max="100" name="probability" id="probability" class="fw-crm__input" value="50">
                </div>
                <div class="fw-crm__form-group">
                    <label for="close_date" class="fw-crm__label">Expected Close Date</label>
                    <input type="date" name="close_date" id="close_date" class="fw-crm__input">
                </div>
                <div class="fw-crm__form-group">
                    <label for="owner_id" class="fw-crm__label">Owner</label>
                    <select name="owner_id" id="owner_id" class="fw-crm__input" required>
                        <?php foreach ($owners as $o): ?>
                            <option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === $userId ? 'selected' : '' ?>><?= htmlspecialchars(trim($o['first_name'] . ' ' . $o['last_name'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fw-crm__form-actions">
                    <button type="submit" class="fw-crm__btn fw-crm__btn--primary">Save Opportunity</button>
                </div>
            </form>
            <div id="oppMessage" class="fw-crm__alert" style="display:none"></div>
        </main>
    </div>
    <script>
    // Attach AJAX submission handler to the opportunity form
    document.getElementById('oppForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var form = this;
        var messageEl = document.getElementById('oppMessage');
        messageEl.style.display = 'none';
        var formData = new FormData(form);
        fetch('/crm/ajax/opportunity_create.php', {
            method: 'POST',
            body: formData
        }).then(function (resp) { return resp.json(); })
        .then(function (data) {
            if (data.ok) {
                // Redirect to the new opportunity view
                window.location.href = '/crm/opp_view.php?opp_id=' + data.opportunity_id;
            } else {
                messageEl.textContent = data.error || 'Failed to save opportunity';
                messageEl.classList.remove('fw-crm__alert--success');
                messageEl.classList.add('fw-crm__alert--error');
                messageEl.style.display = 'block';
            }
        }).catch(function () {
            messageEl.textContent = 'An error occurred';
            messageEl.classList.remove('fw-crm__alert--success');
            messageEl.classList.add('fw-crm__alert--error');
            messageEl.style.display = 'block';
        });
    });
    </script>
</body>
</html>