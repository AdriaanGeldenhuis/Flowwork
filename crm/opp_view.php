<?php
// /crm/opp_view.php – View and edit an existing sales opportunity
// Allows editing key fields and converting a won opportunity into a project.

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-10-07-CRM-8');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];
$role      = $_SESSION['role'] ?? 'viewer';

$oppId = isset($_GET['opp_id']) ? (int)$_GET['opp_id'] : 0;
if ($oppId <= 0) {
    header('Location: /crm/opps_list.php');
    exit;
}

// Fetch opportunity with account and owner info
$stmt = $DB->prepare("SELECT o.*, a.name AS account_name, a.id AS account_id,
                             u.first_name AS owner_first, u.last_name AS owner_last, u.id AS owner_user_id
                      FROM crm_opportunities o
                      LEFT JOIN crm_accounts a ON o.account_id = a.id
                      LEFT JOIN users u ON o.owner_id = u.id
                      WHERE o.id = ? AND o.company_id = ?");
$stmt->execute([$oppId, $companyId]);
$opp = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$opp) {
    header('Location: /crm/opps_list.php');
    exit;
}

// Authorization: viewer can view but not edit; owner can edit; admin can edit
$canEdit = ($role === 'admin' || (int)$opp['owner_user_id'] === (int)$userId);

// Fetch supporting lists for editing if allowed
$accounts = [];
$owners   = [];
if ($canEdit) {
    // Accounts (customers)
    $stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND type = 'customer' ORDER BY name ASC");
    $stmt->execute([$companyId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Owners (users)
    $stmt = $DB->prepare("SELECT id, first_name, last_name FROM users WHERE company_id = ? ORDER BY first_name ASC");
    $stmt->execute([$companyId]);
    $owners = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Stage options
$stageOptions = ['prospect', 'qualification', 'proposal', 'negotiation', 'won', 'lost', 'converted'];

// Fetch user and company names for header
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
    <title>Opportunity – <?= htmlspecialchars($companyName) ?></title>
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
                    <div class="fw-crm__app-name">CRM – Opportunity</div>
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
            <h1 class="fw-crm__title">Opportunity Details</h1>
            <form id="oppEditForm" class="fw-crm__form" method="post" action="/crm/ajax/opportunity_update.php">
                <input type="hidden" name="id" value="<?= (int)$opp['id'] ?>">
                <div class="fw-crm__form-group">
                    <label class="fw-crm__label">Account</label>
                    <?php if ($canEdit): ?>
                        <select name="account_id" id="account_id" class="fw-crm__input" required>
                            <?php foreach ($accounts as $a): ?>
                                <option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === (int)$opp['account_id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <div class="fw-crm__static-text"><?= htmlspecialchars($opp['account_name'] ?? '') ?></div>
                    <?php endif; ?>
                </div>
                <div class="fw-crm__form-group">
                    <label for="title" class="fw-crm__label">Title</label>
                    <?php if ($canEdit): ?>
                        <input type="text" name="title" id="title" class="fw-crm__input" value="<?= htmlspecialchars($opp['title']) ?>" required>
                    <?php else: ?>
                        <div class="fw-crm__static-text"><?= htmlspecialchars($opp['title']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="fw-crm__form-group">
                    <label for="amount" class="fw-crm__label">Amount (R)</label>
                    <?php if ($canEdit): ?>
                        <input type="number" step="0.01" min="0" name="amount" id="amount" class="fw-crm__input" value="<?= htmlspecialchars($opp['amount']) ?>" required>
                    <?php else: ?>
                        <div class="fw-crm__static-text">R<?= number_format((float)$opp['amount'], 2) ?></div>
                    <?php endif; ?>
                </div>
                <div class="fw-crm__form-group">
                    <label for="stage" class="fw-crm__label">Stage</label>
                    <?php if ($canEdit): ?>
                        <select name="stage" id="stage" class="fw-crm__input" required>
                            <?php foreach ($stageOptions as $s): ?>
                                <option value="<?= htmlspecialchars($s) ?>" <?= $s === $opp['stage'] ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <div class="fw-crm__static-text"><?= ucfirst($opp['stage']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="fw-crm__form-group">
                    <label for="probability" class="fw-crm__label">Probability (%)</label>
                    <?php if ($canEdit): ?>
                        <input type="number" step="1" min="0" max="100" name="probability" id="probability" class="fw-crm__input" value="<?= htmlspecialchars($opp['probability']) ?>">
                    <?php else: ?>
                        <div class="fw-crm__static-text"><?= htmlspecialchars($opp['probability']) ?>%</div>
                    <?php endif; ?>
                </div>
                <div class="fw-crm__form-group">
                    <label for="close_date" class="fw-crm__label">Expected Close Date</label>
                    <?php if ($canEdit): ?>
                        <input type="date" name="close_date" id="close_date" class="fw-crm__input" value="<?= htmlspecialchars($opp['close_date']) ?>">
                    <?php else: ?>
                        <div class="fw-crm__static-text"><?= htmlspecialchars($opp['close_date'] ?: '') ?></div>
                    <?php endif; ?>
                </div>
                <div class="fw-crm__form-group">
                    <label for="owner_id" class="fw-crm__label">Owner</label>
                    <?php if ($canEdit && $role === 'admin'): ?>
                        <select name="owner_id" id="owner_id" class="fw-crm__input" required>
                            <?php foreach ($owners as $o): ?>
                                <option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === (int)$opp['owner_user_id'] ? 'selected' : '' ?>><?= htmlspecialchars(trim($o['first_name'] . ' ' . $o['last_name'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <div class="fw-crm__static-text"><?= htmlspecialchars(($opp['owner_first'] ?? '') . ' ' . ($opp['owner_last'] ?? '')) ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($canEdit): ?>
                    <div class="fw-crm__form-actions">
                        <button type="submit" class="fw-crm__btn fw-crm__btn--primary">Save Changes</button>
                        <?php if ($opp['stage'] === 'won'): ?>
                            <button type="button" id="btnConvert" class="fw-crm__btn fw-crm__btn--secondary">Convert to Project</button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </form>
            <div id="oppEditMessage" class="fw-crm__alert" style="display:none"></div>
        </main>
    </div>
    <script>
    (function() {
        const canEdit = <?= $canEdit ? 'true' : 'false' ?>;
        const oppId = <?= (int)$opp['id'] ?>;
        // Form submission for editing
        if (canEdit) {
            document.getElementById('oppEditForm').addEventListener('submit', function(e) {
                e.preventDefault();
                const form = this;
                const messageEl = document.getElementById('oppEditMessage');
                messageEl.style.display = 'none';
                const formData = new FormData(form);
                fetch('/crm/ajax/opportunity_update.php', {
                    method: 'POST',
                    body: formData
                }).then(resp => resp.json())
                .then(data => {
                    if (data.ok) {
                        messageEl.textContent = 'Opportunity updated successfully.';
                        messageEl.classList.remove('fw-crm__alert--error');
                        messageEl.classList.add('fw-crm__alert--success');
                        messageEl.style.display = 'block';
                    } else {
                        messageEl.textContent = data.error || 'Failed to update opportunity.';
                        messageEl.classList.remove('fw-crm__alert--success');
                        messageEl.classList.add('fw-crm__alert--error');
                        messageEl.style.display = 'block';
                    }
                }).catch(() => {
                    messageEl.textContent = 'An error occurred.';
                    messageEl.classList.remove('fw-crm__alert--success');
                    messageEl.classList.add('fw-crm__alert--error');
                    messageEl.style.display = 'block';
                });
            });
            // Convert button
            const btnConvert = document.getElementById('btnConvert');
            if (btnConvert) {
                btnConvert.addEventListener('click', function() {
                    if (!confirm('Convert this opportunity into a project?')) return;
                    const messageEl = document.getElementById('oppEditMessage');
                    messageEl.style.display = 'none';
                    const params = new URLSearchParams();
                    params.append('id', oppId);
                    fetch('/crm/ajax/opportunity_convert.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: params.toString()
                    }).then(resp => resp.json())
                    .then(data => {
                        if (data.ok) {
                            // Redirect to projects overview
                            window.location.href = '/projects/index.php';
                        } else {
                            messageEl.textContent = data.error || 'Failed to convert opportunity.';
                            messageEl.classList.remove('fw-crm__alert--success');
                            messageEl.classList.add('fw-crm__alert--error');
                            messageEl.style.display = 'block';
                        }
                    }).catch(() => {
                        messageEl.textContent = 'An error occurred.';
                        messageEl.classList.remove('fw-crm__alert--success');
                        messageEl.classList.add('fw-crm__alert--error');
                        messageEl.style.display = 'block';
                    });
                });
            }
        }
    })();
    </script>
</body>
</html>