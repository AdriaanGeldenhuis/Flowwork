<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/fa/asset_view.php – View and dispose a fixed asset
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

// Include permissions helper and restrict to admins and bookkeepers
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper']);

define('ASSET_VERSION', '2025-01-21-FA-ASSETVIEW');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

// Fetch user and company details
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$firstName = $stmt->fetchColumn() ?: 'User';

$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$companyName = $stmt->fetchColumn() ?: 'Company';

// Determine asset id from query parameter
$assetId = isset($_GET['aid']) ? (int)$_GET['aid'] : 0;
if (!$assetId) {
    http_response_code(400);
    echo "Invalid asset ID";
    exit;
}

// Fetch asset details
$stmt = $DB->prepare(
    "SELECT asset_id, asset_name, category, purchase_date, purchase_cost_cents,
            salvage_value_cents, useful_life_months, depreciation_method,
            asset_account_id, depreciation_expense_account_id, accumulated_depreciation_account_id,
            accumulated_depreciation_cents, status, disposal_date, disposal_proceeds_cents, disposal_journal_id
     FROM gl_fixed_assets
     WHERE company_id = ? AND asset_id = ?
     LIMIT 1"
);
$stmt->execute([$companyId, $assetId]);
$asset = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$asset) {
    http_response_code(404);
    echo "Asset not found";
    exit;
}

// Compute financial values
$cost      = $asset['purchase_cost_cents'] ? $asset['purchase_cost_cents'] / 100.0 : 0.0;
$salvage   = $asset['salvage_value_cents'] ? $asset['salvage_value_cents'] / 100.0 : 0.0;
$accumDep  = $asset['accumulated_depreciation_cents'] ? $asset['accumulated_depreciation_cents'] / 100.0 : 0.0;
$netBook   = $cost - $accumDep;
$status    = strtolower($asset['status']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fixed Asset Details – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
    <style>
        .fw-finance__details {
            margin-top: 1rem;
        }
        .fw-finance__details dt {
            font-weight: 600;
            margin-top: 0.5rem;
        }
        .fw-finance__details dd {
            margin-left: 0;
            margin-bottom: 0.5rem;
        }
        .fw-finance__form textarea {
            min-height: 4rem;
            resize: vertical;
        }
    </style>
</head>
<body>
<main class="fw-finance">
    <div class="fw-finance__container">
        <!-- Header -->
        <header class="fw-finance__header">
            <div class="fw-finance__brand">
                <div class="fw-finance__logo-tile">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="fw-finance__brand-text">
                    <div class="fw-finance__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-finance__app-name">Asset Details</div>
                </div>
            </div>
            <div class="fw-finance__greeting">
                Hello, <span class="fw-finance__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>
            <div class="fw-finance__controls">
                <a href="/finances/fa/" class="fw-finance__back-btn" title="Back to Fixed Assets">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
            </div>
        </header>
        <div class="fw-finance__main">
            <!-- Asset Details -->
            <h2><?= htmlspecialchars($asset['asset_name']) ?></h2>
            <dl class="fw-finance__details">
                <dt>Category</dt>
                <dd><?= $asset['category'] ? htmlspecialchars($asset['category']) : '-' ?></dd>
                <dt>Purchase Date</dt>
                <dd><?= htmlspecialchars($asset['purchase_date']) ?></dd>
                <dt>Purchase Cost</dt>
                <dd>R <?= number_format($cost, 2) ?></dd>
                <dt>Salvage Value</dt>
                <dd>R <?= number_format($salvage, 2) ?></dd>
                <dt>Useful Life (months)</dt>
                <dd><?= (int)$asset['useful_life_months'] ?></dd>
                <dt>Depreciation Method</dt>
                <dd><?= $asset['depreciation_method'] === 'declining_balance' ? 'Declining Balance' : 'Straight Line' ?></dd>
                <dt>Accumulated Depreciation</dt>
                <dd>R <?= number_format($accumDep, 2) ?></dd>
                <dt>Net Book Value</dt>
                <dd>R <?= number_format($netBook, 2) ?></dd>
                <dt>Status</dt>
                <dd><?= htmlspecialchars(ucfirst($asset['status'])) ?></dd>
            </dl>

            <?php if ($status === 'active'): ?>
            <!-- Disposal Form -->
            <h3>Dispose Asset</h3>
            <form id="disposeForm" class="fw-finance__form" onsubmit="return false;">
                <label>
                    Disposal Date
                    <input type="date" id="disposalDate" value="<?= date('Y-m-d') ?>" required>
                </label>
                <label>
                    Proceeds (R)
                    <input type="number" id="disposalProceeds" step="0.01" min="0" value="0">
                </label>
                <label>
                    Notes (optional)
                    <textarea id="disposalNotes" placeholder="Reason for disposal, details, etc."></textarea>
                </label>
                <button type="submit" class="fw-finance__btn fw-finance__btn--primary">Dispose Asset</button>
                <div id="disposeMessage"></div>
            </form>
            <?php else: ?>
            <p>This asset has been disposed.</p>
            <?php endif; ?>
        </div>
        <footer class="fw-finance__footer">
            <span>Finance Asset View v<?= ASSET_VERSION ?></span>
        </footer>
    </div>
</main>
<script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
<?php if ($status === 'active'): ?>
<script>
document.getElementById('disposeForm').addEventListener('submit', function() {
    var msgDiv = document.getElementById('disposeMessage');
    msgDiv.innerHTML = '';
    var dateVal = document.getElementById('disposalDate').value;
    var procVal = parseFloat(document.getElementById('disposalProceeds').value || 0);
    var notesVal = document.getElementById('disposalNotes').value.trim();
    if (!dateVal) {
        msgDiv.innerHTML = '<div class="fw-finance__alert fw-finance__alert--error">Please select a disposal date.</div>';
        return;
    }
    var payload = {
        asset_id: <?= (int)$assetId ?>,
        disposal_date: dateVal,
        proceeds: procVal,
        notes: notesVal
    };
    fetch('/finances/fa/api/asset_dispose.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    }).then(function(resp) { return resp.json(); }).then(function(data) {
        if (data.success) {
            msgDiv.innerHTML = '<div class="fw-finance__alert fw-finance__alert--success">' + data.message + '</div>';
            // Reload page after a short delay to reflect updated status
            setTimeout(function() { window.location.reload(); }, 1500);
        } else {
            msgDiv.innerHTML = '<div class="fw-finance__alert fw-finance__alert--error">' + (data.message || 'Error disposing asset') + '</div>';
        }
    }).catch(function(err) {
        msgDiv.innerHTML = '<div class="fw-finance__alert fw-finance__alert--error">' + err.message + '</div>';
    });
});
</script>
<?php endif; ?>
</body>
</html>