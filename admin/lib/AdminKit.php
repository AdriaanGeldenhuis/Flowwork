<?php
// /admin/lib/AdminKit.php — small shared helpers for the admin console:
// company / plan / seat look-ups, the audit-trail writer and its labels,
// the role catalogue, the navigation (also the "Find a setting" index) and
// formatting for the pages.

final class AdminKit
{
    /** Roles a company member can have, with what each one means in Flowwork. */
    public const ROLES = [
        'admin' => ['Admin', 'Everything, including these admin settings, people and billing.'],
        'member' => ['Member', 'Works in every module: projects, customers, quotes, mail and more.'],
        'bookkeeper' => ['Bookkeeper', 'Finance, invoices and payroll. Does not use a paid seat.'],
        'viewer' => ['Viewer', 'Can look at everything they are shown, but not change it.'],
        'pos' => ['Counter staff', 'Limited, view-only access for front-of-house staff.'],
    ];

    public static function e($s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }

    public static function company(PDO $db, int $companyId): array
    {
        $st = $db->prepare("SELECT c.*, p.name AS plan_name, p.code AS plan_code, p.max_users AS plan_max_users,
                                   p.max_companies AS plan_max_companies, p.price_monthly_cents
                              FROM companies c LEFT JOIN plans p ON p.id = c.plan_id WHERE c.id = ?");
        $st->execute([$companyId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: ['id' => $companyId, 'name' => 'Your company'];
    }

    /** Paid seats in use (active members that count as a seat) and the plan's limit. */
    public static function seats(PDO $db, int $companyId): array
    {
        $st = $db->prepare("SELECT COUNT(*) FROM user_companies uc JOIN users u ON u.id = uc.user_id
                             WHERE uc.company_id = ? AND u.status = 'active' AND u.is_seat = 1");
        $st->execute([$companyId]);
        $used = (int)$st->fetchColumn();
        $st = $db->prepare("SELECT COALESCE(p.max_users, c.max_users, 1) FROM companies c LEFT JOIN plans p ON p.id = c.plan_id WHERE c.id = ?");
        $st->execute([$companyId]);
        $max = max(1, (int)$st->fetchColumn());
        return ['used' => $used, 'max' => $max, 'free' => max(0, $max - $used)];
    }

    /** Write one line to the company's audit trail. $details: string or array (stored as JSON). */
    public static function audit(PDO $db, int $companyId, int $userId, string $action, $details = null, ?string $entityType = null, ?int $entityId = null): void
    {
        try {
            $db->prepare("INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, ip, timestamp, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
               ->execute([$companyId, $userId ?: null, substr($action, 0, 100), $entityType, $entityId,
                          is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $details,
                          substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
        } catch (Throwable $e) {
            error_log('[admin] audit ' . $action . ': ' . $e->getMessage());
        }
    }

    /**
     * Sliding-window limit for abuse-prone actions (sending invitations, exports).
     * @return int seconds to wait, or 0 when the action may go ahead
     */
    public static function rateLimit(string $key, int $max, int $windowSeconds): int
    {
        $dir = dirname(__DIR__, 2) . '/storage/admin';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        if (!is_dir($dir) || !is_writable($dir)) $dir = sys_get_temp_dir();
        $fh = @fopen($dir . '/rl-' . sha1($key) . '.json', 'c+');
        if (!$fh) return 0;
        try {
            flock($fh, LOCK_EX);
            $now = time();
            $hits = json_decode(stream_get_contents($fh) ?: '[]', true);
            $hits = array_values(array_filter(is_array($hits) ? $hits : [], fn($t) => is_int($t) && $t > $now - $windowSeconds));
            if (count($hits) >= $max) return max(1, $hits[0] + $windowSeconds - $now);
            $hits[] = $now;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($hits));
            return 0;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** Every company this user belongs to (for the switcher). */
    public static function companies(int $userId, int $activeId): array
    {
        return array_map(fn($c) => ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'role' => (string)$c['role'],
                                    'active' => (int)$c['id'] === $activeId, 'subscribed' => (bool)$c['subscription_active']],
                         fw_user_companies($userId));
    }

    // ------------------------------------------------------------ navigation

    /**
     * The console's pages in sidebar order: [group => [[file, label, icon, hint, keywords], ...]].
     * Links to another module's own settings carry 'href' and 'out'.
     */
    public static function nav(): array
    {
        return [
            '' => [
                ['index', 'Overview', 'i-grid', 'Your company at a glance', 'dashboard home start setup checklist'],
            ],
            'Company' => [
                ['company', 'Company profile', 'i-building', 'Name, logo, registration, tax and address', 'business details vat sars cipc address logo contact'],
                ['companies', 'Your companies', 'i-folder', 'Switch between or add companies', 'multi company switch add new'],
            ],
            'People' => [
                ['users', 'People & roles', 'i-users', 'Who can sign in and what they may do', 'users team members staff roles seats suspend remove'],
                ['invites', 'Invitations', 'i-send', 'Invite people by email', 'invite email join link pending'],
                ['boards', 'Projects & access', 'i-kanban', 'Who can open which project and board', 'boards permissions projects members visibility'],
            ],
            'Modules' => [
                ['finance', 'Finance', 'if-ledger', 'Financial year, VAT basis and approvals', 'accounting fiscal year vat approval journals'],
                ['quotes', 'Quotes & Invoices', 'iq-invoice', 'Numbering, payment terms and wording', 'qi quotes invoices numbering terms', '/qi/settings.php', true],
                ['payroll', 'Payroll', 'i-wallet', 'Pay days and posting to Finance', 'wages salaries pay day frequency'],
                ['suppliers', 'Suppliers', 'i-truck', 'Supplier search, RFQs and compliance', 'suppliers ai search rfq compliance bee tax clearance'],
                ['mail', 'Mail', 'i-mail', 'Mailboxes and how Flowwork sends email', 'email smtp mailbox signature'],
                ['calendar', 'Calendar', 'i-calendar', 'Time zone, work hours and shared dates', 'calendar timezone week hours reminders'],
            ],
            'Workspace' => [
                ['automations', 'Automations', 'i-zap', 'What Flowwork does for you on its own', 'reminders notifications scheduled jobs cron'],
                ['integrations', 'Integrations', 'i-link', 'Card payments with Yoco', 'yoco payments card api'],
                ['security', 'Security', 'i-shield', 'Sign-in rules, passwords and devices', 'password login lockout devices sessions'],
                ['data', 'Data & privacy', 'i-download', 'Exports and how long records are kept', 'export backup csv retention popia'],
            ],
            'Account' => [
                ['billing', 'Subscription', 'i-receipt', 'Plan, seats and renewal', 'billing plan upgrade seats price'],
                ['audit', 'Audit trail', 'i-history', 'Everything that changed, and who did it', 'audit log history activity'],
            ],
        ];
    }

    /** The "Find a setting" index: every page, and every setting on it. */
    public static function jumpIndex(): array
    {
        $out = [];
        foreach (self::nav() as $group => $items) {
            foreach ($items as $it) {
                $out[] = ['name' => $it[1], 'hint' => $it[3], 'href' => $it[5] ?? '/admin/' . $it[0] . '.php', 'icon' => $it[2],
                          'kw' => $it[4], 'group' => $group ?: 'Admin', 'page' => true];
            }
        }
        $pageOf = ['calendar' => ['calendar', 'Calendar'], 'security' => ['security', 'Security'], 'data' => ['data', 'Data & privacy'],
                   'payroll' => ['payroll', 'Payroll'], 'suppliers' => ['suppliers', 'Suppliers'], 'finance' => ['finance', 'Finance'],
                   'payments' => ['integrations', 'Integrations']];
        foreach (AdminSettings::areas() as $area => $def) {
            [$file, $label] = $pageOf[$area];
            foreach ($def['fields'] as $f) {
                $out[] = ['name' => $f['label'], 'hint' => '', 'href' => '/admin/' . $file . '.php#set-' . $f['key'], 'icon' => 'i-settings',
                          'kw' => ($f['kw'] ?? '') . ' ' . strip_tags($f['hint'] ?? ''), 'group' => $label, 'page' => false];
            }
        }
        foreach ([
            ['Logo', 'company', 'card-logo', 'Company profile', 'image brand letterhead'],
            ['Banking details', 'company', 'card-banking', 'Company profile', 'bank account branch invoices pay'],
            ['VAT number', 'company', 'f-vat_number', 'Company profile', 'sars vat registration'],
            ['Signed-in devices', 'security', 'card-devices', 'Security', 'sessions phones sign out everywhere'],
            ['Sign-in activity', 'security', 'card-activity', 'Security', 'logins history failed attempts'],
            ['Export people', 'data', 'card-export', 'Data & privacy', 'csv users download'],
            ['Change plan', 'billing', 'card-plans', 'Subscription', 'upgrade downgrade'],
        ] as [$n, $file, $id, $g, $kw]) {
            $out[] = ['name' => $n, 'hint' => '', 'href' => '/admin/' . $file . '.php#' . $id, 'icon' => 'i-chev-right', 'kw' => $kw, 'group' => $g, 'page' => false];
        }
        return $out;
    }

    // ------------------------------------------------------------ formatting

    public static function initials(string $name): string
    {
        $name = trim($name);
        if ($name === '') return '?';
        if (str_contains($name, '@')) return mb_strtoupper(mb_substr($name, 0, 1));
        $w = preg_split('/\s+/', $name);
        return mb_strtoupper(mb_substr($w[0], 0, 1) . (count($w) > 1 ? mb_substr(end($w), 0, 1) : ''));
    }

    public static function hue(string $s): int
    {
        return crc32(mb_strtolower($s)) % 360;
    }

    /** "3 min ago", "yesterday", "12 Mar" … from a DB datetime. */
    public static function ago(?string $dt): string
    {
        $ts = $dt ? strtotime($dt) : false;
        if (!$ts) return '';
        $s = time() - $ts;
        if ($s < 60) return 'just now';
        if ($s < 3600) return floor($s / 60) . ' min ago';
        if ($s < 86400) return floor($s / 3600) . ' h ago';
        if ($s < 172800) return 'yesterday';
        if ($s < 7 * 86400) return floor($s / 86400) . ' days ago';
        return date('Y', $ts) === date('Y') ? date('j M', $ts) : date('j M Y', $ts);
    }

    public static function date(?string $dt, bool $time = false): string
    {
        $ts = $dt ? strtotime($dt) : false;
        if (!$ts) return '';
        return date('j M Y' . ($time ? ', H:i' : ''), $ts);
    }

    public static function money(int $cents): string
    {
        return 'R ' . number_format($cents / 100, $cents % 100 ? 2 : 0, '.', ' ');
    }

    /**
     * A readable line for an audit action: [label, tone]. Unknown actions are
     * turned into words ("crm_account_update" → "Crm account update").
     */
    public static function actionLabel(string $action): array
    {
        static $map = [
            'user_login' => ['Signed in', 'success'], 'user_logout' => ['Signed out', 'muted'], 'user_registered' => ['Joined', 'success'],
            'password_reset' => ['Reset their password', 'warning'], 'password_reset_requested' => ['Asked for a password reset', 'muted'],
            'user_created' => ['Added a person', 'success'], 'user_updated' => ['Changed a person', 'info'], 'user_deleted' => ['Removed a person', 'danger'],
            'user_suspended' => ['Suspended a person', 'danger'], 'user_reactivated' => ['Reactivated a person', 'success'],
            'user_signed_out' => ['Signed a person out', 'warning'], 'device_signed_out' => ['Signed a device out', 'warning'],
            'invite_sent' => ['Sent an invitation', 'info'], 'invite_resent' => ['Resent an invitation', 'info'], 'invite_revoked' => ['Withdrew an invitation', 'warning'],
            'invite_accepted' => ['Accepted an invitation', 'success'],
            'company_updated' => ['Updated the company profile', 'info'], 'company_create' => ['Created a company', 'success'], 'company_switch' => ['Switched company', 'muted'],
            'settings_updated' => ['Changed settings', 'gold'], 'plan_changed' => ['Changed the plan', 'gold'],
            'subscription_canceled' => ['Cancelled the subscription', 'danger'], 'subscription_resumed' => ['Resumed the subscription', 'success'],
            'board_archived' => ['Archived a board', 'warning'], 'board_member_added' => ['Gave board access', 'info'], 'board_member_removed' => ['Removed board access', 'warning'],
            'board_member_updated' => ['Changed board access', 'info'], 'project_member_set' => ['Changed project access', 'info'], 'project_member_removed' => ['Removed from a project', 'warning'],
            'project_visibility' => ['Changed who sees a project', 'info'],
            'data_exported' => ['Exported data', 'gold'], 'audit_pruned' => ['Pruned the audit trail', 'muted'],
            'qi_bank_details_changed' => ['Changed bank details', 'danger'],
            'finance_settings_saved' => ['Changed finance settings', 'gold'], 'finance_settings_updated' => ['Changed finance settings', 'gold'],
            'yoco_updated' => ['Changed card payments', 'gold'],
        ];
        if (isset($map[$action])) return $map[$action];
        $words = trim(str_replace(['_', '-', '.'], ' ', $action));
        return [$words === '' ? 'Activity' : mb_strtoupper(mb_substr($words, 0, 1)) . mb_substr($words, 1), 'muted'];
    }

    /** The details column as a short sentence (JSON objects become "key: value" pairs). */
    public static function detailText(?string $details): string
    {
        $details = trim((string)$details);
        if ($details === '') return '';
        $j = json_decode($details, true);
        if (!is_array($j)) return mb_strimwidth($details, 0, 300, '…');
        $flat = [];
        $walk = function ($v, string $prefix = '') use (&$walk, &$flat) {
            if (is_array($v)) {
                if (array_is_list($v) && !array_filter($v, 'is_array')) { $flat[] = ($prefix !== '' ? $prefix . ': ' : '') . implode(', ', array_map('strval', $v)); return; }
                foreach ($v as $k => $x) $walk($x, $prefix !== '' ? $prefix . ' › ' . $k : (string)$k);
                return;
            }
            if ($v === null || $v === '') return;
            $flat[] = ($prefix !== '' ? str_replace('_', ' ', $prefix) . ': ' : '') . (is_bool($v) ? ($v ? 'yes' : 'no') : (string)$v);
        };
        $walk($j);
        return mb_strimwidth(implode(' · ', $flat), 0, 300, '…');
    }
}
