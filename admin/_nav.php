<?php
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<nav class="fw-admin__nav">
    <div class="fw-admin__nav-header">
        <h2 class="fw-admin__nav-title">Admin</h2>
        <button class="fw-admin__theme-toggle" id="themeToggle" aria-label="Toggle theme">
            <svg class="fw-admin__theme-icon fw-admin__theme-icon--light" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2" fill="none"/>
                <line x1="12" y1="1" x2="12" y2="3" stroke="currentColor" stroke-width="2"/>
                <line x1="12" y1="21" x2="12" y2="23" stroke="currentColor" stroke-width="2"/>
                <line x1="4.22" y1="4.22" x2="5.64" y2="5.64" stroke="currentColor" stroke-width="2"/>
                <line x1="18.36" y1="18.36" x2="19.78" y2="19.78" stroke="currentColor" stroke-width="2"/>
                <line x1="1" y1="12" x2="3" y2="12" stroke="currentColor" stroke-width="2"/>
                <line x1="21" y1="12" x2="23" y2="12" stroke="currentColor" stroke-width="2"/>
                <line x1="4.22" y1="19.78" x2="5.64" y2="18.36" stroke="currentColor" stroke-width="2"/>
                <line x1="18.36" y1="5.64" x2="19.78" y2="4.22" stroke="currentColor" stroke-width="2"/>
            </svg>
            <svg class="fw-admin__theme-icon fw-admin__theme-icon--dark" viewBox="0 0 24 24">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" fill="none"/>
            </svg>
        </button>
    </div>
    
    <ul class="fw-admin__nav-list">
        <li><a href="/admin/index.php" class="fw-admin__nav-link <?= $currentPage === 'index' ? 'fw-admin__nav-link--active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7"/>
                <rect x="14" y="3" width="7" height="7"/>
                <rect x="3" y="14" width="7" height="7"/>
                <rect x="14" y="14" width="7" height="7"/>
            </svg>
            Dashboard
        </a></li>
        
        <li class="fw-admin__nav-divider">Company</li>
        <li><a href="/admin/company.php" class="fw-admin__nav-link <?= $currentPage === 'company' ? 'fw-admin__nav-link--active' : '' ?>">Company Profile</a></li>
        <li><a href="/admin/users.php" class="fw-admin__nav-link <?= $currentPage === 'users' ? 'fw-admin__nav-link--active' : '' ?>">Users & Roles</a></li>
        <li><a href="/admin/invites.php" class="fw-admin__nav-link <?= $currentPage === 'invites' ? 'fw-admin__nav-link--active' : '' ?>">Invites</a></li>
        <li><a href="/admin/boards.php" class="fw-admin__nav-link <?= $currentPage === 'boards' ? 'fw-admin__nav-link--active' : '' ?>">Boards & Permissions</a></li>
        
        <li class="fw-admin__nav-divider">Settings</li>
        <li><a href="/admin/finance.php" class="fw-admin__nav-link <?= $currentPage === 'finance' ? 'fw-admin__nav-link--active' : '' ?>">Finance</a></li>
        <li><a href="/admin/quotes.php" class="fw-admin__nav-link <?= $currentPage === 'quotes' ? 'fw-admin__nav-link--active' : '' ?>">Quotes & Invoicing</a></li>
        <li><a href="/admin/pos.php" class="fw-admin__nav-link <?= $currentPage === 'pos' ? 'fw-admin__nav-link--active' : '' ?>">POS</a></li>
        <li><a href="/admin/payroll.php" class="fw-admin__nav-link <?= $currentPage === 'payroll' ? 'fw-admin__nav-link--active' : '' ?>">Payroll</a></li>
        <li><a href="/admin/suppliers.php" class="fw-admin__nav-link <?= $currentPage === 'suppliers' ? 'fw-admin__nav-link--active' : '' ?>">Suppliers</a></li>
        <li><a href="/admin/mail.php" class="fw-admin__nav-link <?= $currentPage === 'mail' ? 'fw-admin__nav-link--active' : '' ?>">Mail</a></li>
        <li><a href="/admin/calendar.php" class="fw-admin__nav-link <?= $currentPage === 'calendar' ? 'fw-admin__nav-link--active' : '' ?>">Calendar</a></li>
        
        <li class="fw-admin__nav-divider">Advanced</li>
        <li><a href="/admin/automations.php" class="fw-admin__nav-link <?= $currentPage === 'automations' ? 'fw-admin__nav-link--active' : '' ?>">Automations</a></li>
        <li><a href="/admin/integrations.php" class="fw-admin__nav-link <?= $currentPage === 'integrations' ? 'fw-admin__nav-link--active' : '' ?>">Integrations</a></li>
        <li><a href="/admin/security.php" class="fw-admin__nav-link <?= $currentPage === 'security' ? 'fw-admin__nav-link--active' : '' ?>">Security</a></li>
        <li><a href="/admin/data.php" class="fw-admin__nav-link <?= $currentPage === 'data' ? 'fw-admin__nav-link--active' : '' ?>">Data & Backups</a></li>
        
        <li class="fw-admin__nav-divider">Billing</li>
        <li><a href="/admin/billing.php" class="fw-admin__nav-link <?= $currentPage === 'billing' ? 'fw-admin__nav-link--active' : '' ?>">Subscription</a></li>
        <li><a href="/admin/audit.php" class="fw-admin__nav-link <?= $currentPage === 'audit' ? 'fw-admin__nav-link--active' : '' ?>">Audit Log</a></li>
    </ul>
</nav>