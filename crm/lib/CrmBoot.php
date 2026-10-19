<?php
// crm/lib/CrmBoot.php
//
// What every CRM page needs before it draws: the company's look, wording,
// stages, statuses and fields, the person's layout, the people in the company,
// tags and lookups, and what the person may do. Embedded in each page as JSON
// (no extra round trip) and served by /crm/api/boot.php after settings change.

require_once __DIR__ . '/Crm.php';
require_once __DIR__ . '/CrmFields.php';
require_once __DIR__ . '/CrmAccounts.php';
require_once __DIR__ . '/CrmActivity.php';

final class CrmBoot
{
    public static function payload(PDO $db, int $companyId, int $userId, string $role): array
    {
        $cfg = Crm::config($db, $companyId);
        $rules = Crm::rules($db, $companyId);
        $st = $db->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
        $st->execute([$userId]);
        $me = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $st = $db->prepare("SELECT SUM(type = 'customer') c, SUM(type = 'supplier') s FROM crm_accounts WHERE company_id = ? AND deleted_at IS NULL");
        $st->execute([$companyId]);
        $n = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $st = $db->prepare("SELECT COUNT(*) FROM crm_tasks t LEFT JOIN crm_accounts a ON a.id = t.account_id
                             WHERE t.company_id = ? AND t.assigned_to = ? AND t.done_at IS NULL AND t.due_at IS NOT NULL AND DATE(t.due_at) <= CURDATE()
                               AND (t.account_id IS NULL OR a.deleted_at IS NULL)");
        $st->execute([$companyId, $userId]);
        $due = (int)$st->fetchColumn();
        $st = $db->prepare("SELECT COUNT(*) FROM crm_opportunities o JOIN crm_accounts a ON a.id = o.account_id AND a.deleted_at IS NULL WHERE o.company_id = ? AND o.stage NOT IN ('won','lost')");
        $st->execute([$companyId]);
        $openDeals = (int)$st->fetchColumn();

        $activity = [];
        foreach (CrmActivity::META as $k => $m) $activity[] = ['key' => $k, 'label' => $m['label'], 'on' => !empty($cfg['activity'][$k])];
        return [
            'company' => Crm::companyName($db, $companyId),
            'me' => ['id' => $userId, 'first_name' => (string)($me['first_name'] ?? ''), 'name' => trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')), 'email' => (string)($me['email'] ?? '')],
            'role' => $role,
            'can_write' => Crm::canWrite($role),
            'is_admin' => Crm::isAdmin($role),
            'today' => date('Y-m-d'),
            'config' => $cfg,
            'prefs' => Crm::userPrefs($db, $companyId, $userId),
            'stages' => Crm::stages($db, $companyId),
            'activity_types' => $activity,
            'people' => array_values(Crm::people($db, $companyId)),
            'tags' => CrmAccounts::allTags($db, $companyId),
            'lookups' => CrmAccounts::lookups($db, $companyId),
            'fields' => CrmFields::defs($db, $companyId),
            'columns' => Crm::LIST_COLUMNS,
            'address_types' => Crm::ADDRESS_TYPES,
            'rules' => [
                'tags' => $rules['crm_enable_tags'] !== '0',
                'duplicate_check' => $rules['crm_enable_duplicate_check'] !== '0',
                'compliance_badge' => $rules['crm_compliance_badge_enable'] !== '0',
                'default_status' => ['customer' => $rules['crm_default_customer_status'], 'supplier' => $rules['crm_default_supplier_status']],
                'default_currency' => $rules['crm_default_currency'],
            ],
            'counts' => ['customers' => (int)($n['c'] ?? 0), 'suppliers' => (int)($n['s'] ?? 0), 'tasks_due' => $due, 'open_deals' => $openDeals],
        ];
    }

    /** Inline CSS variables for the company's / person's accent, density and text size. */
    public static function styleVars(array $boot): string
    {
        $accent = $boot['prefs']['accent'] ?: $boot['config']['accent'];
        $css = '';
        if ($accent) {
            [$r, $g, $b] = sscanf($accent, '#%02x%02x%02x');
            $dark = sprintf('#%02x%02x%02x', (int)($r * 0.78), (int)($g * 0.78), (int)($b * 0.78));
            $light = sprintf('#%02x%02x%02x', min(255, (int)($r + (255 - $r) * 0.45)), min(255, (int)($g + (255 - $g) * 0.45)), min(255, (int)($b + (255 - $b) * 0.45)));
            $css .= ".fwm.fwc{--accent:$accent;--accent-hover:$dark;--accent-ink:$accent;--accent-solid:$accent;"
                  . "--accent-grad:linear-gradient(140deg,$light 0%,$accent 46%,$dark 100%);"
                  . "--accent-soft:rgba($r,$g,$b,.08);--accent-softer:rgba($r,$g,$b,.045);--accent-ring:rgba($r,$g,$b,.32)}"
                  . ".fwm.fwc[data-theme=\"dark\"]{--accent:$light;--accent-hover:$light;--accent-ink:$light;--accent-solid:$accent;"
                  . "--accent-soft:rgba($r,$g,$b,.16);--accent-softer:rgba($r,$g,$b,.09);--accent-ring:rgba($r,$g,$b,.42)}";
        }
        return $css;
    }
}
