<?php
// /finances/fa/index.php – Fixed Assets overview
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

// Include permissions helper and restrict access to admins and bookkeepers
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper']);

define('ASSET_VERSION', '2025-01-21-FA-INDEX');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

// Fetch user and company details
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user  = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

// Fetch assets
$stmt = $DB->prepare(
    "SELECT asset_id, asset_name, category, purchase_date,
            purchase_cost_cents, salvage_value_cents, useful_life_months,
            depreciation_method, accumulated_depreciation_cents, status
     FROM gl_fixed_assets
     WHERE company_id = ?
     ORDER BY asset_name"
);
$stmt->execute([$companyId]);
$assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch depreciation runs with counts and totals
$stmt = $DB->prepare(
    "SELECT r.id, r.run_month, r.status, r.journal_id,
            (SELECT COUNT(*) FROM fa_depreciation_lines l WHERE l.run_id = r.id) as lines_count,
            (SELECT SUM(amount_cents) FROM fa_depreciation_lines l WHERE l.run_id = r.id) as total_cents
     FROM fa_depreciation_runs r
     WHERE r.company_id = ?
     ORDER BY r.run_month DESC, r.id DESC"
);
$stmt->execute([$companyId]);
$runs = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fixed Assets – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
    <style>
        .fw-finance__table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        .fw-finance__table th,
        .fw-finance__table td {
            border: 1px solid var(--fw-border);
            padding: 0.5rem;
            font-size: 0.875rem;
        }
        .fw-finance__table th {
            background: var(--fw-bg-secondary);
            text-align: left;
        }
        .fw-finance__toolbar {
            margin-top: 1rem;
            margin-bottom: 0.5rem;
            display: flex;
            gap: 1rem;
        }
        .fw-finance__tab-panel {
            display: none;
        }
        .fw-finance__tab-panel--active {
            display: block;
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
                    <div class="fw-finance__app-name">Fixed Assets</div>
                </div>
            </div>
            <div class="fw-finance__greeting">
                Hello, <span class="fw-finance__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>
            <div class="fw-finance__controls">
                <a href="/finances/" class="fw-finance__back-btn" title="Back to Finance">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
                <a href="/" class="fw-finance__home-btn" title="Home">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
                <button class="fw-finance__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                    <svg class="fw-finance__theme-icon fw-finance__theme-icon--light" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2"/>
                        <line x1="12" y1="1" x2="12" y2="3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="12" y1="21" x2="12" y2="23" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="18.36" y1="18.36" x2="19.78" y2="19.78" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="1" y1="12" x2="3" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="21" y1="12" x2="23" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="18.36" y1="5.64" x2="19.78" y2="4.22" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <svg class="fw-finance__theme-icon fw-finance__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>
        </header>
        <!-- Main Content -->
        <div class="fw-finance__main">
            <!-- Tabs -->
            <div class="fw-finance__tabs">
                <button class="fw-finance__tab fw-finance__tab--active" data-tab="assetsPanel">Assets</button>
                <button class="fw-finance__tab" data-tab="runsPanel">Depreciation Runs</button>
            </div>
            <!-- Tab Panels -->
            <div class="fw-finance__tab-content">
                <!-- Assets Panel -->
                <div class="fw-finance__tab-panel fw-finance__tab-panel--active" id="assetsPanel">
                    <div class="fw-finance__toolbar">
                        <a href="/finances/fa/asset_new.php" class="fw-finance__btn fw-finance__btn--primary">+ Add Asset</a>
                    </div>
                    <table class="fw-finance__table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Category</th>
                                <th>Purchase Date</th>
                                <th>Cost</th>
                                <th>Salvage</th>
                                <th>Useful Life (m)</th>
                                <th>Method</th>
                                <th>Accumulated</th>
                                <th>Net Book</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($assets)): ?>
                                <tr><td colspan="10">No assets found. Create one.</td></tr>
                            <?php else: ?>
                                <?php foreach ($assets as $a):
                                    $cost    = $a['purchase_cost_cents'] / 100;
                                    $salvage = $a['salvage_value_cents'] / 100;
                                    $accum   = $a['accumulated_depreciation_cents'] / 100;
                                    $net     = $cost - $accum;
                                ?>
                                <tr>
                                    <td>
                                        <!-- Link asset name to the asset view page for details and disposal -->
                                        <a href="/finances/fa/asset_view.php?aid=<?= (int)$a['asset_id'] ?>">
                                            <?= htmlspecialchars($a['asset_name']) ?>
                                        </a>
                                    </td>
                                    <td><?= htmlspecialchars($a['category'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($a['purchase_date']) ?></td>
                                    <td>R <?= number_format($cost, 2) ?></td>
                                    <td>R <?= number_format($salvage, 2) ?></td>
                                    <td><?= (int)$a['useful_life_months'] ?></td>
                                    <td><?= $a['depreciation_method'] === 'declining_balance' ? 'Declining' : 'Straight' ?></td>
                                    <td>R <?= number_format($accum, 2) ?></td>
                                    <td>R <?= number_format($net, 2) ?></td>
                                    <td><?= htmlspecialchars(ucfirst($a['status'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Runs Panel -->
                <div class="fw-finance__tab-panel" id="runsPanel">
                    <div class="fw-finance__toolbar">
                        <a href="/finances/fa/depreciate.php" class="fw-finance__btn fw-finance__btn--primary">Run Depreciation</a>
                    </div>
                    <table class="fw-finance__table">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Status</th>
                                <th>Lines</th>
                                <th>Total</th>
                                <th>Journal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($runs)): ?>
                                <tr><td colspan="5">No depreciation runs found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($runs as $r):
                                    $total = $r['total_cents'] ? $r['total_cents'] / 100 : 0;
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars(date('Y-m', strtotime($r['run_month']))) ?></td>
                                    <td><?= htmlspecialchars(ucfirst($r['status'])) ?></td>
                                    <td><?= (int)($r['lines_count'] ?? 0) ?></td>
                                    <td>R <?= number_format($total, 2) ?></td>
                                    <td><?= $r['journal_id'] ? '<a href="/finances/journals.php?jid=' . (int)$r['journal_id'] . '">View</a>' : '-' ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <footer class="fw-finance__footer">
            <span>Finance Fixed Assets v<?= ASSET_VERSION ?></span>
        </footer>
    </div>
</main>
<script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
<script>
// Tab switching logic
document.querySelectorAll('.fw-finance__tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.fw-finance__tab').forEach(function(t) { t.classList.remove('fw-finance__tab--active'); });
        document.querySelectorAll('.fw-finance__tab-panel').forEach(function(panel) { panel.classList.remove('fw-finance__tab-panel--active'); });
        var panelId = this.getAttribute('data-tab');
        document.getElementById(panelId).classList.add('fw-finance__tab-panel--active');
        this.classList.add('fw-finance__tab--active');
    });
});
</script>
</body>
</html>