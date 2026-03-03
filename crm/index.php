<?php
// /crm/index.php - COMPLETE WITH OVERVIEW TAB
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Fetch user info
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

// Fetch company name
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

// Get active tab from URL
$activeTab = $_GET['tab'] ?? 'overview';

// Always fetch tab counts (used in tab headers on every tab)
$suppliersCount = $DB->prepare("SELECT COUNT(*) FROM crm_accounts WHERE company_id = ? AND type = 'supplier'");
$suppliersCount->execute([$companyId]);
$totalSuppliers = $suppliersCount->fetchColumn();

$customersCount = $DB->prepare("SELECT COUNT(*) FROM crm_accounts WHERE company_id = ? AND type = 'customer'");
$customersCount->execute([$companyId]);
$totalCustomers = $customersCount->fetchColumn();

// Fetch additional statistics for overview
if ($activeTab === 'overview') {
    $contactsCount = $DB->prepare("SELECT COUNT(*) FROM crm_contacts WHERE company_id = ?");
    $contactsCount->execute([$companyId]);
    $totalContacts = $contactsCount->fetchColumn();

    $interactionsCount = $DB->prepare("SELECT COUNT(*) FROM crm_interactions WHERE company_id = ?");
    $interactionsCount->execute([$companyId]);
    $totalInteractions = $interactionsCount->fetchColumn();

    // Recent activity
    $recentAccounts = $DB->prepare("
        SELECT id, name, type, status, created_at 
        FROM crm_accounts 
        WHERE company_id = ? 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $recentAccounts->execute([$companyId]);
    $recentActivity = $recentAccounts->fetchAll();

    // Top suppliers by preferred
    $topSuppliers = $DB->prepare("
        SELECT id, name, phone, email, preferred
        FROM crm_accounts 
        WHERE company_id = ? AND type = 'supplier' AND status = 'active'
        ORDER BY preferred DESC, name ASC
        LIMIT 5
    ");
    $topSuppliers->execute([$companyId]);
    $preferredSuppliers = $topSuppliers->fetchAll();

    // Top customers
    $topCustomers = $DB->prepare("
        SELECT id, name, phone, email, preferred
        FROM crm_accounts 
        WHERE company_id = ? AND type = 'customer' AND status = 'active'
        ORDER BY preferred DESC, name ASC
        LIMIT 5
    ");
    $topCustomers->execute([$companyId]);
    $topCustomerList = $topCustomers->fetchAll();

    // Expiring compliance docs (next 30 days)
    $expiringDocs = $DB->prepare("
        SELECT 
            cd.id,
            cd.expiry_date,
            ct.name as doc_type,
            a.id as account_id,
            a.name as account_name,
            a.type as account_type
        FROM crm_compliance_docs cd
        JOIN crm_compliance_types ct ON ct.id = cd.type_id
        JOIN crm_accounts a ON a.id = cd.account_id
        WHERE cd.company_id = ? 
          AND cd.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
          AND cd.status IN ('valid', 'expiring')
        ORDER BY cd.expiry_date ASC
        LIMIT 10
    ");
    $expiringDocs->execute([$companyId]);
    $expiringCompliance = $expiringDocs->fetchAll();

    // Status breakdown
    $statusBreakdown = $DB->prepare("
        SELECT
            status,
            COUNT(*) as count
        FROM crm_accounts
        WHERE company_id = ?
        GROUP BY status
    ");
    $statusBreakdown->execute([$companyId]);
    $statuses = $statusBreakdown->fetchAll(PDO::FETCH_KEY_PAIR);

    // Pipeline stats (opportunities)
    $pipelineStmt = $DB->prepare("
        SELECT stage, COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total
        FROM crm_opportunities
        WHERE company_id = ? AND stage NOT IN ('won', 'lost')
        GROUP BY stage
    ");
    $pipelineStmt->execute([$companyId]);
    $pipelineByStage = $pipelineStmt->fetchAll(PDO::FETCH_ASSOC);
    $totalPipelineValue = array_sum(array_column($pipelineByStage, 'total'));

    // Won deals value
    $wonStmt = $DB->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM crm_opportunities
        WHERE company_id = ? AND stage = 'won'
    ");
    $wonStmt->execute([$companyId]);
    $totalWonValue = $wonStmt->fetchColumn();

    // Monthly account growth (last 6 months)
    $monthlyGrowth = $DB->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') as month,
               SUM(CASE WHEN type='supplier' THEN 1 ELSE 0 END) as suppliers,
               SUM(CASE WHEN type='customer' THEN 1 ELSE 0 END) as customers
        FROM crm_accounts
        WHERE company_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month ASC
    ");
    $monthlyGrowth->execute([$companyId]);
    $growthData = $monthlyGrowth->fetchAll(PDO::FETCH_ASSOC);

    // Monthly interactions (last 6 months)
    $monthlyInteractions = $DB->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as cnt
        FROM crm_interactions
        WHERE company_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month ASC
    ");
    $monthlyInteractions->execute([$companyId]);
    $interactionData = $monthlyInteractions->fetchAll(PDO::FETCH_ASSOC);

    // Recent interactions for activity table
    $recentInteractions = $DB->prepare("
        SELECT i.type, i.subject, i.created_at,
               a.name as account_name, a.id as account_id
        FROM crm_interactions i
        JOIN crm_accounts a ON a.id = i.account_id
        WHERE i.company_id = ?
        ORDER BY i.created_at DESC
        LIMIT 8
    ");
    $recentInteractions->execute([$companyId]);
    $latestInteractions = $recentInteractions->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM – <?= htmlspecialchars($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <link rel="stylesheet" href="/crm/assets/crm.css?v=<?= CRM_ASSET_VERSION ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
                    <div class="fw-crm__app-name">CRM</div>
                </div>
            </div>

            <div class="fw-crm__greeting">
                Hello, <span class="fw-crm__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>

            <div class="fw-crm__controls">
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
                        <a href="/crm/settings.php" class="fw-crm__kebab-item">CRM Settings</a>
                        <a href="/crm/import.php" class="fw-crm__kebab-item">Import/Export</a>
                        <a href="/crm/dedupe.php" class="fw-crm__kebab-item">Dedupe & Merge</a>
                    </nav>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="fw-crm__main">
            
            <div class="fw-crm__page-header">
                <h1 class="fw-crm__page-title">Supplier & Customer Relationship Management</h1>
                <p class="fw-crm__page-subtitle">
                    Manage your suppliers, customers, and relationships
                </p>
            </div>

            <!-- Tabs -->
            <div class="fw-crm__view-tabs">
                <a href="/crm/?tab=overview" class="fw-crm__view-tab <?= $activeTab === 'overview' ? 'fw-crm__view-tab--active' : '' ?>">
                    📊 Overview
                </a>
                <a href="/crm/?tab=suppliers" class="fw-crm__view-tab <?= $activeTab === 'suppliers' ? 'fw-crm__view-tab--active' : '' ?>">
                    Suppliers (<?= $totalSuppliers ?? 0 ?>)
                </a>
                <a href="/crm/?tab=customers" class="fw-crm__view-tab <?= $activeTab === 'customers' ? 'fw-crm__view-tab--active' : '' ?>">
                    Customers (<?= $totalCustomers ?? 0 ?>)
                </a>
                <a href="/crm/opps_list.php" class="fw-crm__view-tab">
                    Pipeline
                </a>
            </div>

            <!-- Tab Content -->
            <div class="fw-crm__view-content">
                
                <?php if ($activeTab === 'overview'): ?>
                <!-- OVERVIEW TAB — Real Data -->
                <?php
                    // Format currency
                    function fmtRand($val) {
                        if ($val >= 1000000) return 'R' . number_format($val / 1000000, 1) . 'M';
                        if ($val >= 1000) return 'R' . number_format($val / 1000, 1) . 'k';
                        return 'R' . number_format($val, 0);
                    }
                ?>
                <!-- KPI Cards -->
                <div class="fw-crm__kpi-grid">
                    <div class="fw-crm__kpi-card">
                        <div class="fw-crm__kpi-value"><?= (int)$totalSuppliers ?></div>
                        <div class="fw-crm__kpi-label">Suppliers</div>
                    </div>
                    <div class="fw-crm__kpi-card">
                        <div class="fw-crm__kpi-value"><?= (int)$totalCustomers ?></div>
                        <div class="fw-crm__kpi-label">Customers</div>
                    </div>
                    <div class="fw-crm__kpi-card">
                        <div class="fw-crm__kpi-value"><?= fmtRand($totalPipelineValue) ?></div>
                        <div class="fw-crm__kpi-label">Pipeline Value</div>
                    </div>
                    <div class="fw-crm__kpi-card">
                        <div class="fw-crm__kpi-value"><?= fmtRand($totalWonValue) ?></div>
                        <div class="fw-crm__kpi-label">Won Deals</div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="fw-crm__charts-grid">
                    <div class="fw-crm__chart-card">
                        <h3 class="fw-crm__chart-title">Account Growth (6 months)</h3>
                        <canvas id="chartGrowth" height="200"></canvas>
                    </div>
                    <div class="fw-crm__chart-card">
                        <h3 class="fw-crm__chart-title">Pipeline by Stage</h3>
                        <canvas id="chartPipeline" height="200"></canvas>
                    </div>
                </div>

                <!-- Tables Row -->
                <div class="fw-crm__tables-grid">
                    <div class="fw-crm__table-card">
                        <h3 class="fw-crm__chart-title">Recent Activity</h3>
                        <div class="fw-crm__table-scroll">
                            <table class="fw-crm__data-table">
                                <thead>
                                    <tr><th>Type</th><th>Subject</th><th>Account</th><th>When</th></tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($latestInteractions)): ?>
                                        <tr><td colspan="4" style="text-align:center;color:var(--fw-text-secondary)">No recent activity</td></tr>
                                    <?php else: foreach ($latestInteractions as $ia): ?>
                                        <tr>
                                            <td><span class="fw-crm__pill"><?= htmlspecialchars(ucfirst($ia['type'])) ?></span></td>
                                            <td><?= htmlspecialchars($ia['subject'] ?: '—') ?></td>
                                            <td><a href="/crm/account_view.php?id=<?= (int)$ia['account_id'] ?>"><?= htmlspecialchars($ia['account_name']) ?></a></td>
                                            <td><?= date('d M H:i', strtotime($ia['created_at'])) ?></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="fw-crm__table-card">
                        <h3 class="fw-crm__chart-title">Expiring Compliance (30 days)</h3>
                        <div class="fw-crm__table-scroll">
                            <table class="fw-crm__data-table">
                                <thead>
                                    <tr><th>Document</th><th>Account</th><th>Expires</th></tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($expiringCompliance)): ?>
                                        <tr><td colspan="3" style="text-align:center;color:var(--fw-text-secondary)">No expiring documents</td></tr>
                                    <?php else: foreach ($expiringCompliance as $doc): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($doc['doc_type']) ?></td>
                                            <td><a href="/crm/account_view.php?id=<?= (int)$doc['account_id'] ?>"><?= htmlspecialchars($doc['account_name']) ?></a></td>
                                            <td><?= date('d M Y', strtotime($doc['expiry_date'])) ?></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($activeTab === 'suppliers'): ?>
                <!-- SUPPLIERS TAB -->
                <div class="fw-crm__toolbar">
                    <div class="fw-crm__search-box">
                        <input type="text" id="searchInput" class="fw-crm__search-input" placeholder="Search suppliers...">
                    </div>
                    <div class="fw-crm__filters">
                        <select id="filterStatus" class="fw-crm__select">
                            <option value="">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="prospect">Prospect</option>
                        </select>
                        <select id="filterIndustry" class="fw-crm__select">
                            <option value="">All Industries</option>
                        </select>
                        <select id="filterRegion" class="fw-crm__select">
                            <option value="">All Regions</option>
                        </select>
                    </div>
                    <a href="/crm/account_new.php?type=supplier" class="fw-crm__btn fw-crm__btn--primary">
                        + New Supplier
                    </a>
                </div>
                <div class="fw-crm__accounts-list" id="accountsList">
                    <div class="fw-crm__loading">Loading suppliers...</div>
                </div>
                <?php endif; ?>

                <?php if ($activeTab === 'customers'): ?>
                <!-- CUSTOMERS TAB -->
                <div class="fw-crm__toolbar">
                    <div class="fw-crm__search-box">
                        <input type="text" id="searchInput" class="fw-crm__search-input" placeholder="Search customers...">
                    </div>
                    <div class="fw-crm__filters">
                        <select id="filterStatus" class="fw-crm__select">
                            <option value="">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="prospect">Prospect</option>
                        </select>
                        <select id="filterIndustry" class="fw-crm__select">
                            <option value="">All Industries</option>
                        </select>
                        <select id="filterRegion" class="fw-crm__select">
                            <option value="">All Regions</option>
                        </select>
                    </div>
                    <a href="/crm/account_new.php?type=customer" class="fw-crm__btn fw-crm__btn--primary">
                        + New Customer
                    </a>
                </div>
                <div class="fw-crm__accounts-list" id="accountsList">
                    <div class="fw-crm__loading">Loading customers...</div>
                </div>
                <?php endif; ?>

            </div>

        </main>

        <!-- Footer -->
        <footer class="fw-crm__footer">
            <span>CRM v<?= CRM_ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>

    </div>

    <script src="/crm/assets/crm.js?v=<?= CRM_ASSET_VERSION ?>"></script>
    <?php if ($activeTab === 'overview'): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
      const isDark = document.querySelector('.fw-crm').getAttribute('data-theme') === 'dark';
      const gridColor = isDark ? 'rgba(255,255,255,.05)' : 'rgba(0,0,0,.05)';
      const tickColor = isDark ? '#9fb0c8' : '#6b7280';

      // Account Growth chart (real monthly data)
      const growthCanvas = document.getElementById('chartGrowth');
      if (growthCanvas) {
        const growthData = <?= json_encode($growthData) ?>;
        const labels = growthData.map(r => r.month);
        const suppliers = growthData.map(r => parseInt(r.suppliers));
        const customers = growthData.map(r => parseInt(r.customers));
        new Chart(growthCanvas, {
          type: 'bar',
          data: {
            labels: labels.length ? labels : ['No data'],
            datasets: [
              { label: 'Suppliers', data: suppliers.length ? suppliers : [0], backgroundColor: '#06b6d4', borderRadius: 6 },
              { label: 'Customers', data: customers.length ? customers : [0], backgroundColor: '#10b981', borderRadius: 6 }
            ]
          },
          options: {
            responsive: true, maintainAspectRatio: true,
            plugins: { legend: { position: 'bottom', labels: { color: tickColor } } },
            scales: {
              x: { grid: { display: false }, ticks: { color: tickColor } },
              y: { grid: { color: gridColor }, ticks: { color: tickColor, stepSize: 1 } }
            }
          }
        });
      }

      // Pipeline by Stage chart (real data)
      const pipelineCanvas = document.getElementById('chartPipeline');
      if (pipelineCanvas) {
        const pipelineData = <?= json_encode($pipelineByStage) ?>;
        const stageColors = { prospect: '#8b5cf6', qualification: '#3b82f6', proposal: '#06b6d4', negotiation: '#f59e0b' };
        new Chart(pipelineCanvas, {
          type: 'doughnut',
          data: {
            labels: pipelineData.length ? pipelineData.map(r => r.stage.charAt(0).toUpperCase() + r.stage.slice(1)) : ['No data'],
            datasets: [{
              data: pipelineData.length ? pipelineData.map(r => parseFloat(r.total)) : [1],
              backgroundColor: pipelineData.length ? pipelineData.map(r => stageColors[r.stage] || '#6b7280') : ['#374151']
            }]
          },
          options: {
            responsive: true, maintainAspectRatio: true,
            plugins: {
              legend: { position: 'bottom', labels: { color: tickColor } },
              tooltip: {
                callbacks: {
                  label: function(ctx) { return ctx.label + ': R' + ctx.parsed.toLocaleString(); }
                }
              }
            }
          }
        });
      }
    });
    </script>
    <?php endif; ?>
</body>
</html>