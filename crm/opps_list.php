<?php
// /crm/opps_list.php – Sales Pipeline board
// Displays a Kanban board of sales opportunities by stage. Users can drag
// opportunities between stages; the board totals update automatically.

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

// CRM_ASSET_VERSION centralized in init.php as CRM_CRM_ASSET_VERSION

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

// Board filters (GET; server-rendered board reloads on submit)
$filterOwner = (int)($_GET['owner'] ?? 0);
$filterFrom = trim($_GET['close_from'] ?? '');
$filterTo = trim($_GET['close_to'] ?? '');
if ($filterFrom !== '' && strtotime($filterFrom) === false) $filterFrom = '';
if ($filterTo !== '' && strtotime($filterTo) === false) $filterTo = '';

// Owner list for the filter dropdown
$ownersStmt = $DB->prepare("SELECT id, first_name, last_name FROM users WHERE company_id = ? ORDER BY first_name, last_name");
$ownersStmt->execute([$companyId]);
$ownerOptions = $ownersStmt->fetchAll(PDO::FETCH_ASSOC);

// Load all opportunities for this company, ordering by stage order and created date
$sql = "SELECT o.*, a.name AS account_name, u.first_name AS owner_first, u.last_name AS owner_last
        FROM crm_opportunities o
        LEFT JOIN crm_accounts a ON o.account_id = a.id
        LEFT JOIN users u ON o.owner_id = u.id
        WHERE o.company_id = ?";
$params = [$companyId];
if ($filterOwner) {
    $sql .= " AND o.owner_id = ?";
    $params[] = $filterOwner;
}
if ($filterFrom !== '') {
    $sql .= " AND o.close_date >= ?";
    $params[] = $filterFrom;
}
if ($filterTo !== '') {
    $sql .= " AND o.close_date <= ?";
    $params[] = $filterTo;
}
$sql .= " ORDER BY FIELD(o.stage, $stagePlaceholders), o.created_at ASC";
$stmt = $DB->prepare($sql);
$params = array_merge($params, $stages);
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
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <link rel="stylesheet" href="/crm/assets/crm.css?v=<?= CRM_ASSET_VERSION ?>">
    <link rel="stylesheet" href="/crm/opps/opps.css?v=<?= CRM_ASSET_VERSION ?>">
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

                <button class="fw-crm__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                    <svg class="fw-crm__theme-icon fw-crm__theme-icon--light" viewBox="0 0 24 24" fill="none">
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
                    <svg class="fw-crm__theme-icon fw-crm__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>

                <div class="fw-crm__menu-wrapper">
                    <button class="fw-crm__kebab-toggle" id="kebabToggle" aria-label="Menu">
                        <svg viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
                        </svg>
                    </button>
                    <nav class="fw-crm__kebab-menu" id="kebabMenu" aria-hidden="true">
                        <a href="/crm/" class="fw-crm__kebab-item">Back to CRM</a>
                        <a href="/crm/settings.php" class="fw-crm__kebab-item">CRM Settings</a>
                    </nav>
                </div>
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

            <!-- Board filters -->
            <form method="GET" class="fw-opps__filters">
                <select name="owner" class="fw-crm__select">
                    <option value="">All owners</option>
                    <?php foreach ($ownerOptions as $o): ?>
                        <option value="<?= (int)$o['id'] ?>" <?= $filterOwner === (int)$o['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars(trim($o['first_name'] . ' ' . $o['last_name'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label class="fw-opps__filter-label">Close date
                    <input type="date" name="close_from" class="fw-crm__input" value="<?= htmlspecialchars($filterFrom) ?>">
                </label>
                <label class="fw-opps__filter-label">to
                    <input type="date" name="close_to" class="fw-crm__input" value="<?= htmlspecialchars($filterTo) ?>">
                </label>
                <button type="submit" class="fw-crm__btn fw-crm__btn--secondary">Filter</button>
                <?php if ($filterOwner || $filterFrom !== '' || $filterTo !== ''): ?>
                    <a href="/crm/opps_list.php" class="fw-crm__btn fw-crm__btn--secondary">Clear</a>
                <?php endif; ?>
            </form>
            <!-- Mobile stage selector -->
            <select id="mobileStageSelect" class="fw-opps__mobile-stage-select">
                <?php foreach ($stages as $i => $stage): ?>
                    <option value="<?= htmlspecialchars($stage) ?>" <?= $i === 0 ? 'selected' : '' ?>><?= ucfirst($stage) ?> (<?= (int)$stageCounts[$stage] ?>)</option>
                <?php endforeach; ?>
            </select>
            <div id="kanbanBoard" class="fw-opps__board">
                <?php foreach ($stages as $i => $stage): ?>
                    <div class="fw-opps__column <?= $i === 0 ? 'fw-opps__column--active' : '' ?>" data-stage="<?= htmlspecialchars($stage) ?>">
                        <div class="fw-opps__column-header">
                            <span class="fw-opps__column-title"><?= ucfirst($stage) ?></span>
                            <span class="fw-opps__column-count" id="count-<?= htmlspecialchars($stage) ?>"><?= (int)$stageCounts[$stage] ?></span>
                            <span class="fw-opps__column-total" id="total-<?= htmlspecialchars($stage) ?>">R<?= number_format($stageTotals[$stage], 2) ?></span>
                        </div>
                        <div class="fw-opps__items">
                            <?php foreach ($board[$stage] as $opp): ?>
                                <?php
                                // Stale = open deal whose close date passed, or with no
                                // stage movement (fallback: created) for over 30 days
                                $isOpen = !in_array($opp['stage'], ['won', 'lost', 'converted']);
                                $lastMove = $opp['stage_changed_at'] ?? null;
                                $ageRef = $lastMove ?: $opp['created_at'];
                                $isStale = $isOpen && (
                                    (!empty($opp['close_date']) && strtotime($opp['close_date']) < strtotime('today'))
                                    || ($ageRef && strtotime($ageRef) < strtotime('-30 days'))
                                );
                                ?>
                                <div class="fw-opps__card<?= $isStale ? ' fw-opps__card--stale' : '' ?>" draggable="true"
                                     data-id="<?= (int)$opp['id'] ?>"
                                     data-amount="<?= (float)$opp['amount'] ?>">
                                    <div class="fw-opps__card-title">
                                        <a href="/crm/opp_view.php?opp_id=<?= (int)$opp['id'] ?>"><?= htmlspecialchars($opp['title']) ?></a>
                                        <?php if ($isStale): ?>
                                            <span class="fw-opps__stale-flag" title="No movement in 30+ days or close date passed">⚠</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="fw-opps__card-info">
                                        <?= htmlspecialchars($opp['account_name'] ?? '—') ?><br>
                                        R<?= number_format((float)$opp['amount'], 2) ?>
                                        <?php if ((float)$opp['probability'] > 0): ?>
                                            <span class="fw-opps__prob-chip"><?= round((float)$opp['probability']) ?>%</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="fw-opps__card-meta">
                                        Owner: <?= htmlspecialchars(($opp['owner_first'] ?? '') . ' ' . ($opp['owner_last'] ?? '')) ?>
                                        <?php if (!empty($opp['close_date'])): ?>
                                            <br>Close: <?= htmlspecialchars($opp['close_date']) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </main>
    </div>
    <!-- Loss reason modal (shown when a deal is dropped into Lost) -->
    <div class="fw-crm__modal-overlay" id="lossReasonModal">
        <div class="fw-crm__modal">
            <div class="fw-crm__modal-header">
                <h2 class="fw-crm__modal-title">Why was this deal lost?</h2>
                <button type="button" class="fw-crm__modal-close" id="lossReasonClose">
                    <svg viewBox="0 0 24 24" fill="none">
                        <line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
            <div class="fw-crm__modal-body">
                <div class="fw-crm__form-group">
                    <label class="fw-crm__label">Reason (optional, helps win/loss review)</label>
                    <input type="text" id="lossReasonInput" class="fw-crm__input" maxlength="255"
                           placeholder="e.g. price, timing, went with competitor">
                </div>
            </div>
            <div class="fw-crm__modal-footer">
                <button type="button" class="fw-crm__btn fw-crm__btn--secondary" id="lossReasonSkip">Skip</button>
                <button type="button" class="fw-crm__btn fw-crm__btn--primary" id="lossReasonSave">Save Reason</button>
            </div>
        </div>
    </div>

    <script src="/crm/assets/crm.js?v=<?= CRM_ASSET_VERSION ?>"></script>
    <script src="/crm/opps/js/kanban.js?v=<?= CRM_ASSET_VERSION ?>"></script>
</body>
</html>