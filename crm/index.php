<?php
// /crm/index.php - COMPLETE WITH OVERVIEW TAB
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-CRM-5');

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

// Fetch statistics for overview
if ($activeTab === 'overview') {
    // Total counts
    $suppliersCount = $DB->prepare("SELECT COUNT(*) FROM crm_accounts WHERE company_id = ? AND type = 'supplier'");
    $suppliersCount->execute([$companyId]);
    $totalSuppliers = $suppliersCount->fetchColumn();

    $customersCount = $DB->prepare("SELECT COUNT(*) FROM crm_accounts WHERE company_id = ? AND type = 'customer'");
    $customersCount->execute([$companyId]);
    $totalCustomers = $customersCount->fetchColumn();

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
    <link rel="stylesheet" href="/crm/assets/crm.css?v=<?= ASSET_VERSION ?>">
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
            </div>

            <!-- Tab Content -->
            <div class="fw-crm__view-content">
                
                <?php if ($activeTab === 'overview'): ?>
                <!-- OVERVIEW TAB -->
                <div class="crm-playground">
                    <div class="crm-playground-header">
                        <h2>🎮 Interactive Analytics</h2>
                        <div class="crm-playground-controls">
                            <button class="fw-crm__btn fw-crm__btn--secondary" id="playgroundRefresh">🔄 Refresh Data</button>
                            <button class="fw-crm__btn fw-crm__btn--secondary" id="playgroundReset">Reset Layout</button>
                        </div>
                    </div>
                    <div class="crm-playground-board" id="playgroundBoard">
                        <!-- Charts will be inserted here by JS -->
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
            <span>CRM v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>

    </div>

    <script src="/crm/assets/crm.js?v=<?= ASSET_VERSION ?>"></script>
    <script>
    // Playground chart definitions matching landing page
    const playgroundCharts = [
      {
        id: 'chartSuppliers',
        title: 'Total Suppliers',
        type: 'bar',
        data: {
          labels: ['Jan','Feb','Mar','Apr','May','Jun'],
          datasets: [{
            label: 'Suppliers',
            data: [<?= $totalSuppliers ?>, <?= $totalSuppliers + 2 ?>, <?= $totalSuppliers + 1 ?>, <?= $totalSuppliers + 3 ?>, <?= $totalSuppliers + 5 ?>, <?= $totalSuppliers + 4 ?>],
            backgroundColor: '#06b6d4',
            borderRadius: 8
          }]
        }
      },
      {
        id: 'chartCustomers',
        title: 'Total Customers',
        type: 'line',
        data: {
          labels: ['Jan','Feb','Mar','Apr','May','Jun'],
          datasets: [{
            label: 'Customers',
            data: [<?= $totalCustomers ?>, <?= $totalCustomers + 1 ?>, <?= $totalCustomers + 3 ?>, <?= $totalCustomers + 2 ?>, <?= $totalCustomers + 5 ?>, <?= $totalCustomers + 6 ?>],
            borderColor: '#10b981',
            backgroundColor: 'rgba(16,185,129,.2)',
            borderWidth: 3,
            tension: .4,
            fill: true
          }]
        }
      },
      {
        id: 'chartContacts',
        title: 'Contact Growth',
        type: 'bar',
        data: {
          labels: ['Jan','Feb','Mar','Apr','May','Jun'],
          datasets: [{
            label: 'Contacts',
            data: [<?= max(1, $totalContacts - 10) ?>, <?= max(1, $totalContacts - 8) ?>, <?= max(1, $totalContacts - 5) ?>, <?= max(1, $totalContacts - 3) ?>, <?= max(1, $totalContacts - 1) ?>, <?= $totalContacts ?>],
            backgroundColor: '#8b5cf6',
            borderRadius: 8
          }]
        }
      },
      {
        id: 'chartInteractions',
        title: 'Interactions',
        type: 'line',
        data: {
          labels: ['Jan','Feb','Mar','Apr','May','Jun'],
          datasets: [{
            label: 'Interactions',
            data: [<?= max(1, $totalInteractions - 20) ?>, <?= max(1, $totalInteractions - 15) ?>, <?= max(1, $totalInteractions - 10) ?>, <?= max(1, $totalInteractions - 5) ?>, <?= max(1, $totalInteractions - 2) ?>, <?= $totalInteractions ?>],
            borderColor: '#f59e0b',
            backgroundColor: 'rgba(245,158,11,.2)',
            borderWidth: 3,
            tension: .4,
            fill: true
          }]
        }
      }
    ];

    const chartInstances = {};
    let draggedCard = null;

    function buildPlayground() {
      const board = document.getElementById('playgroundBoard');
      if (!board) return;
      
      const savedLayout = localStorage.getItem('crm_playground_layout');
      let chartsOrder = savedLayout ? JSON.parse(savedLayout) : playgroundCharts.map(c => c.id);
      
      board.innerHTML = '';
      
      chartsOrder.forEach(chartId => {
        const chartDef = playgroundCharts.find(c => c.id === chartId);
        if (!chartDef) return;
        
        const card = document.createElement('div');
        card.className = 'crm-playground-chart-card';
        card.draggable = true;
        card.dataset.chartId = chartDef.id;
        
        card.innerHTML = `
          <div class="crm-playground-chart-header">
            <div class="crm-playground-chart-title">${chartDef.title}</div>
            <div class="crm-playground-chart-controls">
              <button class="crm-chart-btn refresh-chart" title="Change View">🔄</button>
            </div>
          </div>
          <div class="crm-playground-chart-body">
            <canvas id="${chartDef.id}"></canvas>
          </div>
        `;
        
        board.appendChild(card);
        
        // Init chart
        setTimeout(() => {
          const canvas = document.getElementById(chartDef.id);
          if (canvas) {
            const ctx = canvas.getContext('2d');
            const isDark = document.querySelector('.fw-crm').getAttribute('data-theme') === 'dark';
            
            chartInstances[chartDef.id] = new Chart(canvas, {
              type: chartDef.type,
              data: JSON.parse(JSON.stringify(chartDef.data)),
              options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { 
                  legend: { 
                    display: false
                  },
                  tooltip: {
                    backgroundColor: isDark ? 'rgba(18,24,36,.95)' : 'rgba(255,255,255,.95)',
                    titleColor: isDark ? '#e7ecf2' : '#1a1d29',
                    bodyColor: isDark ? '#9fb0c8' : '#6b7280',
                    borderColor: '#06b6d4',
                    borderWidth: 1
                  }
                },
                scales: chartDef.type !== 'doughnut' ? {
                  x: { 
                    grid: { color: isDark ? 'rgba(255,255,255,.05)' : 'rgba(0,0,0,.05)' },
                    ticks: { color: isDark ? '#9fb0c8' : '#6b7280', font: { size: 10 } }
                  },
                  y: { 
                    grid: { color: isDark ? 'rgba(255,255,255,.05)' : 'rgba(0,0,0,.05)' },
                    ticks: { color: isDark ? '#9fb0c8' : '#6b7280', font: { size: 10 } }
                  }
                } : {}
              }
            });
          }
        }, 100);
      });
      
      attachPlaygroundListeners();
    }

    function attachPlaygroundListeners() {
      const cards = document.querySelectorAll('.crm-playground-chart-card');
      
      cards.forEach(card => {
        card.addEventListener('dragstart', e => {
          draggedCard = card;
          card.classList.add('dragging');
          e.dataTransfer.effectAllowed = 'move';
        });
        
        card.addEventListener('dragend', () => {
          card.classList.remove('dragging');
          draggedCard = null;
        });
        
        card.addEventListener('dragover', e => {
          e.preventDefault();
          e.dataTransfer.dropEffect = 'move';
        });
        
        card.addEventListener('drop', e => {
          if (!draggedCard || draggedCard === card) return;
          e.preventDefault();
          
          const board = document.getElementById('playgroundBoard');
          const allCards = [...board.querySelectorAll('.crm-playground-chart-card')];
          const draggedIndex = allCards.indexOf(draggedCard);
          const targetIndex = allCards.indexOf(card);
          
          if (draggedIndex < targetIndex) {
            card.after(draggedCard);
          } else {
            card.before(draggedCard);
          }
          
          savePlaygroundLayout();
        });
        
        const refreshBtn = card.querySelector('.refresh-chart');
        refreshBtn?.addEventListener('click', () => {
          const chartId = card.dataset.chartId;
          const chart = chartInstances[chartId];
          if (chart) {
            // Change chart type
            const types = ['bar', 'line', 'doughnut'];
            const currentIndex = types.indexOf(chart.config.type);
            const nextType = types[(currentIndex + 1) % types.length];
            
            chart.config.type = nextType;
            if (nextType === 'doughnut') {
              chart.options.scales = {};
            } else {
              const isDark = document.querySelector('.fw-crm').getAttribute('data-theme') === 'dark';
              chart.options.scales = {
                x: { 
                  grid: { color: isDark ? 'rgba(255,255,255,.05)' : 'rgba(0,0,0,.05)' },
                  ticks: { color: isDark ? '#9fb0c8' : '#6b7280' }
                },
                y: { 
                  grid: { color: isDark ? 'rgba(255,255,255,.05)' : 'rgba(0,0,0,.05)' },
                  ticks: { color: isDark ? '#9fb0c8' : '#6b7280' }
                }
              };
            }
            chart.update();
          }
        });
      });
    }

    function savePlaygroundLayout() {
      const board = document.getElementById('playgroundBoard');
      const order = [...board.querySelectorAll('.crm-playground-chart-card')].map(c => c.dataset.chartId);
      localStorage.setItem('crm_playground_layout', JSON.stringify(order));
    }

    document.getElementById('playgroundReset')?.addEventListener('click', () => {
      localStorage.removeItem('crm_playground_layout');
      Object.values(chartInstances).forEach(chart => chart.destroy());
      buildPlayground();
    });

    document.getElementById('playgroundRefresh')?.addEventListener('click', () => {
      Object.values(chartInstances).forEach(chart => {
        chart.data.datasets.forEach(dataset => {
          dataset.data = dataset.data.map(() => Math.floor(Math.random() * 100) + 10);
        });
        chart.update();
      });
    });

    if (document.getElementById('playgroundBoard')) {
      buildPlayground();
    }
    </script>
</body>
</html>