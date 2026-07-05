<?php
// /finances/partials/nav.php
// Single source of truth for finance navigation: the header kebab menu
// (every finance page) and the dashboard quick-actions deck.
//
// Role lists mirror each target page's requireRoles() gate — keep them in
// sync when a page's gate changes. requireRoles semantics apply: admin
// always sees everything, other roles must be listed.

require_once __DIR__ . '/../permissions.php';

if (!function_exists('fw_finance_role_can')) {
    function fw_finance_role_can(string $role, array $roles): bool {
        return $role === 'admin' || in_array($role, $roles, true);
    }
}

if (!function_exists('fw_finance_nav_items')) {
    /**
     * Kebab-menu items covering every finance section, filtered to the
     * current user's role. Label => href, insertion order = display order.
     */
    function fw_finance_nav_items(PDO $DB): array {
        $role = fw_get_user_role($DB);
        $items = [
            ['label' => 'Journal Entries',       'href' => '/finances/journals.php',       'roles' => ['bookkeeper']],
            ['label' => 'Chart of Accounts',     'href' => '/finances/chart.php',          'roles' => ['bookkeeper']],
            ['label' => 'Bank & Reconciliation', 'href' => '/finances/bank/',              'roles' => ['bookkeeper']],
            ['label' => 'VAT & VAT201',          'href' => '/finances/vat.php',            'roles' => ['bookkeeper', 'viewer']],
            ['label' => 'Receivables (AR)',      'href' => '/finances/ar/',                'roles' => ['bookkeeper', 'viewer']],
            ['label' => 'Payables (AP)',         'href' => '/finances/ap/',                'roles' => ['bookkeeper', 'viewer']],
            ['label' => 'Fixed Assets',          'href' => '/finances/fa/',                'roles' => ['bookkeeper']],
            ['label' => 'Budgets',               'href' => '/finances/budgets/edit.php',   'roles' => ['bookkeeper']],
            ['label' => 'Reports',               'href' => '/finances/reports.php',        'roles' => ['bookkeeper', 'viewer']],
            ['label' => 'Exchange Rates',        'href' => '/finances/exchange_rates.php', 'roles' => ['bookkeeper']],
            ['label' => 'Period Locks',          'href' => '/finances/period_locks.php',   'roles' => ['bookkeeper']],
            ['label' => 'Year End',              'href' => '/finances/year_end.php',       'roles' => []],
            ['label' => 'Settings',              'href' => '/finances/settings.php',       'roles' => []],
        ];
        $menu = [];
        foreach ($items as $item) {
            if (fw_finance_role_can($role, $item['roles'])) {
                $menu[$item['label']] = $item['href'];
            }
        }
        return $menu;
    }
}

if (!function_exists('fw_finance_quick_actions')) {
    /**
     * Dashboard quick-actions deck: the full SARS bookkeeping cycle —
     * capture (journals, bills), bank reconciliation, VAT201, AR/AP,
     * fixed assets & wear-and-tear, budgets, FX, period locks, year-end
     * close, reporting and setup. Filtered to the current user's role.
     */
    function fw_finance_quick_actions(PDO $DB): array {
        $role = fw_get_user_role($DB);
        $actions = [
            ['label' => 'New Journal',       'icon' => '📝', 'href' => '/finances/journals.php',       'roles' => ['bookkeeper']],
            ['label' => 'Receivables',       'icon' => '📄', 'href' => '/finances/ar/',                'roles' => ['bookkeeper', 'viewer']],
            ['label' => 'New Bill',          'icon' => '📥', 'href' => '/finances/ap/bill_new.php',    'roles' => ['bookkeeper']],
            ['label' => 'Payables',          'icon' => '📑', 'href' => '/finances/ap/',                'roles' => ['bookkeeper', 'viewer']],
            ['label' => 'Pay Suppliers',     'icon' => '💸', 'href' => '/finances/ap/payment_new.php', 'roles' => ['bookkeeper']],
            ['label' => 'Bank & Reconcile',  'icon' => '🏦', 'href' => '/finances/bank/',              'roles' => ['bookkeeper']],
            ['label' => 'VAT & VAT201',      'icon' => '🧾', 'href' => '/finances/vat.php',            'roles' => ['bookkeeper', 'viewer']],
            ['label' => 'Reports',           'icon' => '📈', 'href' => '/finances/reports.php',        'roles' => ['bookkeeper', 'viewer']],
            ['label' => 'Chart of Accounts', 'icon' => '📊', 'href' => '/finances/chart.php',          'roles' => ['bookkeeper']],
            ['label' => 'Fixed Assets',      'icon' => '🏭', 'href' => '/finances/fa/',                'roles' => ['bookkeeper']],
            ['label' => 'Budgets',           'icon' => '📅', 'href' => '/finances/budgets/edit.php',   'roles' => ['bookkeeper']],
            ['label' => 'Exchange Rates',    'icon' => '💱', 'href' => '/finances/exchange_rates.php', 'roles' => ['bookkeeper']],
            ['label' => 'Period Locks',      'icon' => '🔒', 'href' => '/finances/period_locks.php',   'roles' => ['bookkeeper']],
            ['label' => 'Year End',          'icon' => '🏁', 'href' => '/finances/year_end.php',       'roles' => []],
            ['label' => 'Settings',          'icon' => '⚙️', 'href' => '/finances/settings.php',       'roles' => []],
        ];
        return array_values(array_filter(
            $actions,
            static fn(array $a): bool => fw_finance_role_can($role, $a['roles'])
        ));
    }
}
