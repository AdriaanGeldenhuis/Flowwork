<?php
// /crm/opps_list.php – Sales Pipeline board
// Displays a Kanban board of sales opportunities by stage. Users can drag
// opportunities between stages; the board totals update automatically.

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-10-07-CRM-8');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];
$role      = $_SESSION['role'] ?? 'viewer';

// Fetch user and company info for greeting
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$firstName = $user['first_name'] ?? 'User';

$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);
$companyName = $company['name'] ?? 'Company';

// Define pipeline stages in desired order
$stages = ['prospect', 'qualification', 'proposal', 'negotiation', 'won', 'lost'];

// Build placeholders for FIELD order by clause
$stagePlaceholders = implode(',', array_fill(0, count($stages), '?'));

// Load all opportunities for this company, ordering by stage order and created date
$sql = "SELECT o.*, a.name AS account_name, u.first_name AS owner_first, u.last_name AS owner_last
        FROM crm_opportunities o
        LEFT JOIN crm_accounts a ON o.account_id = a.id
        LEFT JOIN users u ON o.owner_id = u.id
        WHERE o.company_id = ?
        ORDER BY FIELD(o.stage, $stagePlaceholders), o.created_at ASC";
$stmt = $DB->prepare($sql);
// Merge companyId and stages as parameters
$params = array_merge([$companyId], $stages);
$stmt->execute($params);
$opps = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Organize opportunities by stage
$board = [];
$stageTotals = [];
$stageCounts = [];
foreach ($stages as $stage) {
    $board[$stage] = [];
    $stageTotals[$stage] = 0;
    $stageCounts[$stage] = 0;
}
foreach ($opps as $opp) {
    $stage = $opp['stage'];
    if (!isset($board[$stage])) {
        $board[$stage] = [];
        $stageTotals[$stage] = 0;
        $stageCounts[$stage] = 0;
    }
    $board[$stage][] = $opp;
    $stageTotals[$stage] += (float)$opp['amount'];
    $stageCounts[$stage]++;
}

// Calculate total pipeline amount
$totalPipeline = array_sum($stageTotals);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Pipeline – <?= htmlspecialchars($companyName) ?></title>
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
                    <div class="fw-crm__app-name">CRM – Sales Pipeline</div>
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
        <!-- Main content -->
        <main class="fw-crm__main">
            <div class="fw-opps__toolbar">
                <h1 class="fw-opps__title">Sales Pipeline</h1>
                <div class="fw-opps__actions">
                    <button id="btnNewOpp" class="fw-crm__btn fw-crm__btn--primary" onclick="window.location.href='/crm/opp_new.php'">New Opportunity</button>
                </div>
            </div>
            <div class="fw-opps__summary">
                Total Pipeline Value: <strong>R<?= number_format($totalPipeline, 2) ?></strong>
            </div>
            <div id="kanbanBoard" class="fw-opps__board">
                <?php foreach ($stages as $stage): ?>
                    <div class="fw-opps__column" data-stage="<?= htmlspecialchars($stage) ?>">
                        <div class="fw-opps__column-header">
                            <span class="fw-opps__column-title"><?= ucfirst($stage) ?></span>
                            <span class="fw-opps__column-count" id="count-<?= htmlspecialchars($stage) ?>"><?= (int)$stageCounts[$stage] ?></span>
                            <span class="fw-opps__column-total" id="total-<?= htmlspecialchars($stage) ?>">R<?= number_format($stageTotals[$stage], 2) ?></span>
                        </div>
                        <div class="fw-opps__items">
                            <?php foreach ($board[$stage] as $opp): ?>
                                <div class="fw-opps__card" draggable="true" data-id="<?= (int)$opp['id'] ?>">
                                    <div class="fw-opps__card-title">
                                        <a href="/crm/opp_view.php?opp_id=<?= (int)$opp['id'] ?>"><?= htmlspecialchars($opp['title']) ?></a>
                                    </div>
                                    <div class="fw-opps__card-info">
                                        <?= htmlspecialchars($opp['account_name'] ?? '—') ?><br>
                                        R<?= number_format((float)$opp['amount'], 2) ?>
                                    </div>
                                    <div class="fw-opps__card-meta">
                                        Owner: <?= htmlspecialchars(($opp['owner_first'] ?? '') . ' ' . ($opp['owner_last'] ?? '')) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </main>
    </div>
    <script src="/crm/opps/js/kanban.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>