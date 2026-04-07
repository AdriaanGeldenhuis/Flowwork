<?php
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<nav class="fw-admin__nav">
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