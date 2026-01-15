<?php
// /suppliers_ai/history.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-22-SAI-1');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Fetch search history
$stmt = $DB->prepare("
    SELECT 
        q.id,
        q.q_text,
        q.took_ms,
        q.created_at,
        u.first_name,
        u.last_name,
        COUNT(DISTINCT c.id) as result_count,
        COUNT(DISTINCT a.id) as action_count
    FROM ai_queries q
    LEFT JOIN users u ON q.user_id = u.id
    LEFT JOIN ai_candidates c ON c.query_id = q.id AND c.company_id = q.company_id
    LEFT JOIN ai_actions a ON a.query_id = q.id AND a.company_id = q.company_id
    WHERE q.company_id = ?
    GROUP BY q.id
    ORDER BY q.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute([$companyId, $perPage, $offset]);
$queries = $stmt->fetchAll();

// Get total count
$stmt = $DB->prepare("SELECT COUNT(*) as total FROM ai_queries WHERE company_id = ?");
$stmt->execute([$companyId]);
$totalQueries = $stmt->fetch()['total'];
$totalPages = ceil($totalQueries / $perPage);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search History – Suppliers AI – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/suppliers_ai/style.css?v=<?= ASSET_VERSION ?>">
</head>
<body>
    <main class="fw-suppliers-ai">
        <div class="fw-suppliers-ai__container">
            
            <!-- Header -->
            <header class="fw-suppliers-ai__header">
                <div class="fw-suppliers-ai__brand">
                    <div class="fw-suppliers-ai__logo-tile">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <polyline points="3.27 6.96 12 12.01 20.73 6.96" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <line x1="12" y1="22.08" x2="12" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div class="fw-suppliers-ai__brand-text">
                        <div class="fw-suppliers-ai__company-name"><?= htmlspecialchars($companyName) ?></div>
                        <div class="fw-suppliers-ai__app-name">Search History</div>
                    </div>
                </div>

                <div class="fw-suppliers-ai__greeting">
                    Hello, <span class="fw-suppliers-ai__greeting-name"><?= htmlspecialchars($firstName) ?></span>
                </div>

                <div class="fw-suppliers-ai__controls">
                    <a href="/suppliers_ai/" class="fw-suppliers-ai__home-btn" title="Back to Search">
                        <svg viewBox="0 0 24 24" fill="none">
                            <line x1="19" y1="12" x2="5" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <polyline points="12 19 5 12 12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    
                    <a href="/" class="fw-suppliers-ai__home-btn" title="Home">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    
                    <button class="fw-suppliers-ai__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                        <svg class="fw-suppliers-ai__theme-icon fw-suppliers-ai__theme-icon--light" viewBox="0 0 24 24" fill="none">
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
                        <svg class="fw-suppliers-ai__theme-icon fw-suppliers-ai__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </div>
            </header>

            <!-- History List -->
            <div class="fw-suppliers-ai__history-panel">
                <div class="fw-suppliers-ai__history-header">
                    <h2 class="fw-suppliers-ai__section-title">Recent Searches (<?= number_format($totalQueries) ?>)</h2>
                </div>

                <?php if (empty($queries)): ?>
                    <div class="fw-suppliers-ai__empty-state">
                        No search history yet. Start searching to see results here.
                    </div>
                <?php else: ?>
                    <div class="fw-suppliers-ai__history-list">
                        <?php foreach ($queries as $q): ?>
                        <div class="fw-suppliers-ai__history-card">
                            <div class="fw-suppliers-ai__history-card-header">
                                <h3 class="fw-suppliers-ai__history-query">"<?= htmlspecialchars($q['q_text']) ?>"</h3>
                                <span class="fw-suppliers-ai__history-time"><?= date('d M Y H:i', strtotime($q['created_at'])) ?></span>
                            </div>
                            <div class="fw-suppliers-ai__history-card-meta">
                                <span>👤 <?= htmlspecialchars($q['first_name'] . ' ' . $q['last_name']) ?></span>
                                <span>📊 <?= $q['result_count'] ?> results</span>
                                <span>⚡ <?= $q['took_ms'] ?>ms</span>
                                <span>🎯 <?= $q['action_count'] ?> actions</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="fw-suppliers-ai__pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1 ?>" class="fw-suppliers-ai__btn fw-suppliers-ai__btn--secondary">← Previous</a>
                        <?php endif; ?>
                        
                        <span class="fw-suppliers-ai__pagination-info">Page <?= $page ?> of <?= $totalPages ?></span>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?= $page + 1 ?>" class="fw-suppliers-ai__btn fw-suppliers-ai__btn--secondary">Next →</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Footer -->
            <footer class="fw-suppliers-ai__footer">
                <span>Suppliers AI v<?= ASSET_VERSION ?></span>
                <span id="themeIndicator">Theme: Light</span>
            </footer>

        </div>
    </main>

    <script src="/suppliers_ai/suppliers_ai.js?v=<?= ASSET_VERSION ?>"></script>
    <style>
        .fw-suppliers-ai__history-panel {
            background: var(--fw-panel-bg);
            border: 1px solid var(--fw-panel-border);
            border-radius: var(--fw-radius-lg);
            padding: var(--fw-spacing-xl);
            box-shadow: var(--fw-shadow-md);
            backdrop-filter: blur(12px);
        }
        .fw-suppliers-ai__history-header {
            margin-bottom: var(--fw-spacing-lg);
            padding-bottom: var(--fw-spacing-md);
            border-bottom: 2px solid var(--fw-border);
        }
        .fw-suppliers-ai__history-list {
            display: flex;
            flex-direction: column;
            gap: var(--fw-spacing-md);
        }
        .fw-suppliers-ai__history-card {
            padding: var(--fw-spacing-lg);
            background: var(--fw-highlight);
            border: 1px solid var(--fw-border);
            border-radius: var(--fw-radius-md);
            transition: all 0.2s ease;
        }
        .fw-suppliers-ai__history-card:hover {
            box-shadow: var(--fw-shadow-sm);
            transform: translateY(-2px);
        }
        .fw-suppliers-ai__history-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: var(--fw-spacing-md);
            margin-bottom: var(--fw-spacing-sm);
        }
        .fw-suppliers-ai__history-query {
            font-size: 16px;
            font-weight: 600;
            color: var(--fw-text-primary);
            margin: 0;
            flex: 1;
        }
        .fw-suppliers-ai__history-time {
            font-size: 13px;
            color: var(--fw-text-muted);
            white-space: nowrap;
        }
        .fw-suppliers-ai__history-card-meta {
            display: flex;
            flex-wrap: wrap;
            gap: var(--fw-spacing-md);
            font-size: 13px;
            color: var(--fw-text-secondary);
        }
        .fw-suppliers-ai__pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: var(--fw-spacing-lg);
            margin-top: var(--fw-spacing-xl);
            padding-top: var(--fw-spacing-lg);
            border-top: 1px solid var(--fw-border);
        }
        .fw-suppliers-ai__pagination-info {
            font-size: 14px;
            color: var(--fw-text-muted);
        }
    </style>
</body>
</html>