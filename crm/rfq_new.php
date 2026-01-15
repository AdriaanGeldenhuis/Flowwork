<?php
// /crm/rfq_new.php – Stub UI for creating a new RFQ
// Provides a simple form to pick a supplier, set a due date and add
// free‑text line items. The form submits via AJAX to the
// /crm/ajax/rfq_create.php endpoint to save the request.

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-CRM-4');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Fetch user and company info for greeting and title
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

// Fetch supplier accounts to populate the dropdown
$stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND type = 'supplier' ORDER BY name ASC");
$stmt->execute([$companyId]);
$suppliers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create RFQ – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/crm/assets/crm.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-crm">
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
                    <div class="fw-crm__app-name">CRM – New RFQ</div>
                </div>
            </div>
            <div class="fw-crm__greeting">
                Hello, <span class="fw-crm__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>
            <div class="fw-crm__controls">
                <a href="/crm/" class="fw-crm__back-btn" title="Back to CRM home">
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
        <!-- Content -->
        <main class="fw-crm__main">
            <h1 class="fw-crm__title">Request for Quotation</h1>
            <form id="rfqForm" class="fw-crm__form" method="post" action="/crm/ajax/rfq_create.php">
                <div class="fw-crm__form-group">
                    <label for="supplier" class="fw-crm__label">Supplier</label>
                    <select name="account_id" id="supplier" class="fw-crm__input" required>
                        <option value="" disabled selected>Select a supplier</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fw-crm__form-group">
                    <label for="due_date" class="fw-crm__label">Due date</label>
                    <input type="date" name="due_date" id="due_date" class="fw-crm__input" required>
                </div>
                <div class="fw-crm__form-group">
                    <label for="lines" class="fw-crm__label">Line items / requirements</label>
                    <textarea name="lines" id="lines" class="fw-crm__textarea" rows="5" placeholder="Describe the items or services you require" required></textarea>
                </div>
                <div class="fw-crm__form-actions">
                    <button type="submit" class="fw-crm__btn fw-crm__btn--primary">Save RFQ</button>
                </div>
            </form>
            <div id="rfqMessage" class="fw-crm__alert" style="display:none"></div>
        </main>
    </div>
    <script>
    // Attach AJAX submission handler to the RFQ form
    document.getElementById('rfqForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var form = this;
        var messageEl = document.getElementById('rfqMessage');
        messageEl.style.display = 'none';
        var formData = new FormData(form);
        fetch('/crm/ajax/rfq_create.php', {
            method: 'POST',
            body: formData
        }).then(function(resp) {
            return resp.json();
        }).then(function(data) {
            if (data.ok) {
                messageEl.textContent = 'RFQ saved successfully.';
                messageEl.classList.remove('fw-crm__alert--error');
                messageEl.classList.add('fw-crm__alert--success');
                messageEl.style.display = 'block';
                form.reset();
            } else {
                messageEl.textContent = data.error || 'Error saving RFQ.';
                messageEl.classList.remove('fw-crm__alert--success');
                messageEl.classList.add('fw-crm__alert--error');
                messageEl.style.display = 'block';
            }
        }).catch(function(err) {
            messageEl.textContent = 'An unexpected error occurred.';
            messageEl.classList.remove('fw-crm__alert--success');
            messageEl.classList.add('fw-crm__alert--error');
            messageEl.style.display = 'block';
        });
    });
    </script>
</body>
</html>