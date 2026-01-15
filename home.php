<?php
// home.php
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/auth_gate.php';

// Asset version for cache busting
define('ASSET_VERSION', '2025-01-21-3');

// Fetch user data from session
$firstName = $_SESSION['user_first_name'] ?? 'Welcome';
$userId = $_SESSION['user_id'];

// Fetch company data
$stmt = $DB->prepare("
  SELECT c.name, c.business_type 
  FROM companies c
  JOIN users u ON u.company_id = c.id
  WHERE u.id = ?
");
$stmt->execute([$userId]);
$company = $stmt->fetch();

$companyName = $company['name'] ?? 'Your Company';
$businessType = $company['business_type'] ?? 'construction';
$companyLogo = null; // TODO: implement logo upload
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flowwork – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/home/style.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-home">
    <div class="fw-home__container">
        <!-- Header -->
        <header class="fw-home__header">
            <div class="fw-home__brand">
                <div class="fw-home__logo-tile">
                    <?php if ($companyLogo): ?>
                        <img src="<?= htmlspecialchars($companyLogo) ?>" alt="<?= htmlspecialchars($companyName) ?> logo" class="fw-home__company-logo">
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M20 7L12 3L4 7V17L12 21L20 17V7Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M12 12L20 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M12 12V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M12 12L4 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    <?php endif; ?>
                </div>
                <div class="fw-home__brand-text">
                    <div class="fw-home__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-home__app-name">Flowwork</div>
                </div>
            </div>

            <div class="fw-home__greeting">
                Hello, <span class="fw-home__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>

            <div class="fw-home__controls">
                <button class="fw-home__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                    <svg class="fw-home__theme-icon fw-home__theme-icon--light" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
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
                    <svg class="fw-home__theme-icon fw-home__theme-icon--dark" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>

                <div class="fw-home__menu-wrapper">
                    <button class="fw-home__kebab-toggle" id="kebabToggle" aria-label="Open menu" aria-expanded="false" aria-controls="kebabMenu">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
                        </svg>
                    </button>
                    <nav class="fw-home__kebab-menu" id="kebabMenu" role="menu" aria-hidden="true">
                        <a href="/admin/" class="fw-home__kebab-item" role="menuitem">Admin/Settings</a>
                        <a href="/contact/" class="fw-home__kebab-item" role="menuitem">Contact Us</a>
                        <a href="/help/" class="fw-home__kebab-item" role="menuitem">Help</a>
                        <a href="/logout.php" class="fw-home__kebab-item" role="menuitem">Logout</a>
                    </nav>
                </div>
            </div>
        </header>

        <!-- Main Grid -->
        <main class="fw-home__main">
            <div class="fw-home__grid">
                <!-- Projects -->
                <a href="/projects/" class="fw-home__tile" data-accent="projects">
                    <div class="fw-home__tile-inner">
                        <div class="fw-home__tile-icon">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <rect x="3" y="3" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/>
                                <rect x="14" y="3" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/>
                                <rect x="3" y="14" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/>
                                <rect x="14" y="14" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/>
                            </svg>
                        </div>
                        <h2 class="fw-home__tile-title">Projects & Boards (WORK OS)</h2>
                        <p class="fw-home__tile-subtitle">Manage and track all projects</p>
                    </div>
                </a>

                <!-- Suppliers -->
                <a href="/crm/" class="fw-home__tile" data-accent="suppliers">
                    <div class="fw-home__tile-inner">
                        <div class="fw-home__tile-icon">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="2"/>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <h2 class="fw-home__tile-title">Suppliers & CRM</h2>
                        <p class="fw-home__tile-subtitle">Supplier & Customer relationships and data</p>
                    </div>
                </a>

                <!-- Quotes -->
                <a href="/qi/" class="fw-home__tile" data-accent="quotes">
                    <div class="fw-home__tile-inner">
                        <div class="fw-home__tile-icon">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M14 2v6h6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <line x1="16" y1="13" x2="8" y2="13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                <line x1="16" y1="17" x2="8" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                <line x1="10" y1="9" x2="8" y2="9" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <h2 class="fw-home__tile-title">Quotes & Invoices</h2>
                        <p class="fw-home__tile-subtitle">Create and manage quotes and invoices</p>
                    </div>
                </a>

                <!-- Finance -->
                <a href="/finances/" class="fw-home__tile" data-accent="finances">
                    <div class="fw-home__tile-inner">
                        <div class="fw-home__tile-icon">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <line x1="12" y1="1" x2="12" y2="23" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <h2 class="fw-home__tile-title">Finance/Accounting</h2>
                        <p class="fw-home__tile-subtitle">Complete Financial/Accounting software</p>
                    </div>
                </a>

                <!-- Receipts -->
                <a href="/receipts/" class="fw-home__tile" data-accent="receipts">
                    <div class="fw-home__tile-inner">
                        <div class="fw-home__tile-icon">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1-2-1z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <line x1="16" y1="8" x2="8" y2="8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                <line x1="16" y1="12" x2="8" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                <line x1="16" y1="16" x2="8" y2="16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <h2 class="fw-home__tile-title">Receipts AI</h2>
                        <p class="fw-home__tile-subtitle">Track and manage receipts</p>
                    </div>
                </a>

                <!-- Wages -->
                <a href="/payroll/" class="fw-home__tile" data-accent="payroll">
                    <div class="fw-home__tile-inner">
                        <div class="fw-home__tile-icon">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <rect x="2" y="7" width="20" height="14" rx="2" stroke="currentColor" stroke-width="2"/>
                                <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <circle cx="12" cy="14" r="2" stroke="currentColor" stroke-width="2"/>
                            </svg>
                        </div>
                        <h2 class="fw-home__tile-title">Payroll Software</h2>
                        <p class="fw-home__tile-subtitle">Employee wages and payroll</p>
                    </div>
                </a>

                <!-- Suppliers AI -->
                <a href="/suppliers_ai/" class="fw-home__tile" data-accent="suppliers-ai">
                    <div class="fw-home__tile-inner">
                        <div class="fw-home__tile-icon">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
                                <path d="M12 1v6m0 6v6M4.22 4.22l4.24 4.24m7.08 7.08l4.24 4.24M1 12h6m6 0h6M4.22 19.78l4.24-4.24m7.08-7.08l4.24-4.24" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <h2 class="fw-home__tile-title">Suppliers AI</h2>
                        <p class="fw-home__tile-subtitle">AI-powered supplier insights</p>
                    </div>
                </a>

                <!-- Shopping -->
                <a href="/shopping/" class="fw-home__tile" data-accent="shopping">
                    <div class="fw-home__tile-inner">
                        <div class="fw-home__tile-icon">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="9" cy="21" r="1" stroke="currentColor" stroke-width="2"/>
                                <circle cx="20" cy="21" r="1" stroke="currentColor" stroke-width="2"/>
                                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <h2 class="fw-home__tile-title">Shopping List</h2>
                        <p class="fw-home__tile-subtitle">Procurement and supplies</p>
                    </div>
                </a>

                <!-- Mail -->
                <a href="/mail/" class="fw-home__tile" data-accent="mail">
                    <div class="fw-home__tile-inner">
                        <div class="fw-home__tile-icon">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="m22 6-10 7L2 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <h2 class="fw-home__tile-title">Mail</h2>
                        <p class="fw-home__tile-subtitle">Messages and communication</p>
                    </div>
                </a>

                <!-- Calendar -->
                <a href="/calendar/" class="fw-home__tile" data-accent="calendar">
                    <div class="fw-home__tile-inner">
                        <div class="fw-home__tile-icon">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/>
                                <line x1="16" y1="2" x2="16" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                <line x1="8" y1="2" x2="8" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                <line x1="3" y1="10" x2="21" y2="10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <h2 class="fw-home__tile-title">Calendar</h2>
                        <p class="fw-home__tile-subtitle">Schedule and events</p>
                    </div>
                </a>

                <!-- Drive -->
                <a href="https://flowdrive.co.za/" class="fw-home__tile" data-accent="settings">
                    <div class="fw-home__tile-inner">
                        <div class="fw-home__tile-icon">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
                                <path d="M12 1v6m0 6v6M4.22 4.22l4.24 4.24m7.08 7.08l4.24 4.24M1 12h6m6 0h6M4.22 19.78l4.24-4.24m7.08-7.08l4.24-4.24" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <h2 class="fw-home__tile-title">DRIVE</h2>
                        <p class="fw-home__tile-subtitle">Cloud Storage for all your files</p>
                    </div>
                </a>

                <!-- Admin -->
                <a href="/admin/" class="fw-home__tile" data-accent="admin">
                    <div class="fw-home__tile-inner">
                        <div class="fw-home__tile-icon">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M9 12l2 2 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <h2 class="fw-home__tile-title">Admin</h2>
                        <p class="fw-home__tile-subtitle">Administration tools</p>
                    </div>
                </a>
            </div>
        </main>

        <!-- Footer -->
        <footer class="fw-home__footer">
            <span>Version <?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>
    </div>

    <script src="/home/home.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>