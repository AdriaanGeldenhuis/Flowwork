<?php
// mail/lib/RulesEngine.php
//
// Inbox rules, applied to new incoming mail during sync (and on demand from
// Settings → Rules → "Run now"). Understands two stored formats:
//   UI:     conditions_json {"match":"all|any","items":[{field,operator,value}]}
//           (or a bare list of items = "all"), actions_json [{type,value}]
//   Legacy: conditions_json {"contains":[...]} / actions_json {"add_tags":[...]}
// Smart labels (RFQ / Invoice / Payment Proof) are applied when enabled in
// preferences.

class RulesEngine
{
    public const FIELDS = ['from', 'to', 'subject', 'body', 'any'];
    public const OPERATORS = ['contains', 'not_contains', 'equals', 'starts_with', 'ends_with'];
    public const ACTION_TYPES = ['label', 'move', 'mark_read', 'star'];
    public const MOVE_TARGETS = ['Archive', 'Spam', 'Trash'];

    public static function detectIntent(string $subject, string $body): string
    {
        $hay = strtolower($subject . ' ' . $body);
        if (preg_match('/\brfq\b|request for quotation|quote needed/i', $hay)) return 'rfq';
        // Proofs of payment usually quote an invoice number, so check them first.
        if (preg_match('/proof of payment|\bpop\b|\beft\b|bank confirmation|remittance advice/i', $hay)) return 'payment_proof';
        if (preg_match('/\binvoice\b|\binv[\d-]+|\bstatement\b/i', $hay)) return 'invoice_query';
        return 'general';
    }

    /** Active rules visible to the mailbox owner, in priority order. */
    public static function loadRules(PDO $db, int $companyId, int $userId): array
    {
        $st = $db->prepare("SELECT * FROM email_rules
                             WHERE company_id = ? AND (user_id = ? OR user_id IS NULL) AND is_active = 1
                          ORDER BY priority ASC, rule_id ASC");
        $st->execute([$companyId, $userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Normalise stored JSON to ['match'=>..., 'items'=>[...]] and [['type','value'],...]. */
    public static function decode(array $rule): array
    {
        $c = json_decode((string)($rule['conditions_json'] ?? ''), true);
        $a = json_decode((string)($rule['actions_json'] ?? ''), true);
        $match = 'all';
        $items = [];
        if (is_array($c)) {
            if (isset($c['items']) && is_array($c['items'])) {
                $match = ($c['match'] ?? 'all') === 'any' ? 'any' : 'all';
                $items = $c['items'];
            } elseif (isset($c['contains']) && is_array($c['contains'])) {
                $match = 'any';
                foreach ($c['contains'] as $w) $items[] = ['field' => 'any', 'operator' => 'contains', 'value' => (string)$w];
            } elseif (array_is_list($c)) {
                $items = $c;
            }
        }
        $actions = [];
        if (is_array($a)) {
            if (isset($a['add_tags']) && is_array($a['add_tags'])) {
                foreach ($a['add_tags'] as $t) $actions[] = ['type' => 'label', 'value' => (string)$t];
            } elseif (array_is_list($a)) {
                foreach ($a as $x) {
                    if (!is_array($x)) continue;
                    $type = $x['type'] ?? '';
                    if ($type === 'tag') $type = 'label';
                    if (in_array($type, self::ACTION_TYPES, true)) $actions[] = ['type' => $type, 'value' => (string)($x['value'] ?? '')];
                }
            }
        }
        $clean = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $f = in_array($it['field'] ?? '', self::FIELDS, true) ? $it['field'] : 'any';
            $o = in_array($it['operator'] ?? '', self::OPERATORS, true) ? $it['operator'] : 'contains';
            $v = trim((string)($it['value'] ?? ''));
            if ($v !== '') $clean[] = ['field' => $f, 'operator' => $o, 'value' => $v];
        }
        return ['match' => $match, 'items' => $clean, 'actions' => $actions];
    }

    public static function matches(array $decoded, array $msg): bool
    {
        if (!$decoded['items']) return false;
        foreach ($decoded['items'] as $it) {
            $hay = match ($it['field']) {
                'from'    => $msg['from'],
                'to'      => $msg['to'],
                'subject' => $msg['subject'],
                'body'    => $msg['body'],
                default   => $msg['subject'] . "\n" . $msg['body'] . "\n" . $msg['from'],
            };
            $hay = mb_strtolower($hay);
            $v = mb_strtolower($it['value']);
            $ok = match ($it['operator']) {
                'equals'       => trim($hay) === $v || ($it['field'] === 'from' && str_contains("\n" . $hay . "\n", "\n" . $v . "\n")),
                'starts_with'  => str_starts_with(trim($hay), $v),
                'ends_with'    => str_ends_with(trim($hay), $v),
                'not_contains' => !str_contains($hay, $v),
                default        => self::containsWord($hay, $v),
            };
            if ($decoded['match'] === 'any' && $ok) return true;
            if ($decoded['match'] === 'all' && !$ok) return false;
        }
        return $decoded['match'] === 'all';
    }

    /** Short tokens ("pop", "eft", "inv") must match as whole words, longer ones as substrings. */
    private static function containsWord(string $hay, string $needle): bool
    {
        if (mb_strlen($needle) <= 4 && preg_match('/^\w+$/u', $needle)) {
            return (bool)preg_match('/\b' . preg_quote($needle, '/') . '\b/u', $hay);
        }
        return str_contains($hay, $needle);
    }

    /**
     * Apply rules (+ smart labels) to one stored email. Returns applied action labels.
     */
    public static function applyToEmail(PDO $db, int $emailId, ?array $rules = null, ?bool $smartLabels = null): array
    {
        $st = $db->prepare("SELECT e.*, a.user_id AS owner_id FROM emails e
                              JOIN email_accounts a ON a.account_id = e.account_id WHERE e.email_id = ?");
        $st->execute([$emailId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return [];
        $companyId = (int)$row['company_id'];
        $userId = (int)$row['owner_id'];
        if ($rules === null) $rules = self::loadRules($db, $companyId, $userId);
        if ($smartLabels === null) $smartLabels = (bool)(MailRepo::prefs($db, $companyId, $userId)['smart_labels'] ?? true);

        $msg = [
            'from'    => trim(($row['sender_name'] ?? '') . "\n" . ($row['sender'] ?? '')),
            'to'      => trim(($row['to_recipients'] ?? '') . "\n" . ($row['cc_recipients'] ?? '')),
            'subject' => (string)($row['subject'] ?? ''),
            'body'    => mb_substr((string)($row['body_text'] ?? ''), 0, 20000),
        ];
        $applied = [];

        foreach ($rules as $rule) {
            $d = self::decode($rule);
            if (!$d['actions'] || !self::matches($d, $msg)) continue;
            foreach ($d['actions'] as $act) {
                $row = self::reload($db, $emailId) ?? $row;
                switch ($act['type']) {
                    case 'label':
                        $tagId = self::tagId($db, $companyId, $act['value']);
                        if ($tagId) { MailActions::label($db, [$row], $tagId, true); $applied[] = 'label:' . $act['value']; }
                        break;
                    case 'move':
                        $target = in_array($act['value'], self::MOVE_TARGETS, true) ? $act['value'] : 'Archive';
                        $action = ['Archive' => 'archive', 'Spam' => 'spam', 'Trash' => 'trash'][$target];
                        MailActions::apply($db, [$row], $action);
                        $applied[] = 'move:' . $target;
                        break;
                    case 'mark_read':
                        MailActions::apply($db, [$row], 'read');
                        $applied[] = 'read';
                        break;
                    case 'star':
                        MailActions::apply($db, [$row], 'star');
                        $applied[] = 'star';
                        break;
                }
            }
            if ((int)$rule['stop_processing'] === 1) break;
        }

        if ($smartLabels && $row['direction'] === 'incoming') {
            $intent = self::detectIntent($msg['subject'], $msg['body']);
            $tagName = ['rfq' => 'RFQ', 'invoice_query' => 'Invoice', 'payment_proof' => 'Payment Proof'][$intent] ?? null;
            if ($tagName) {
                $tagId = self::tagId($db, $companyId, $tagName, false);
                if ($tagId) { MailActions::label($db, [$row], $tagId, true); $applied[] = 'smart:' . $tagName; }
            }
        }
        return $applied;
    }

    /** Backwards-compatible entry point (workers). */
    public static function applyToMessage(PDO $DB, int $emailId, int $companyId): void
    {
        self::applyToEmail($DB, $emailId);
    }

    private static function reload(PDO $db, int $emailId): ?array
    {
        $st = $db->prepare("SELECT * FROM emails WHERE email_id = ?");
        $st->execute([$emailId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Resolve a label by id or name (company first, then global); optionally create it. */
    public static function tagId(PDO $db, int $companyId, string $value, bool $create = true): int
    {
        $value = trim($value);
        if ($value === '') return 0;
        if (ctype_digit($value)) {
            return MailRepo::labelAllowed($db, $companyId, (int)$value) ? (int)$value : 0;
        }
        $st = $db->prepare("SELECT tag_id FROM email_tags WHERE company_id IN (?, 0) AND name = ? ORDER BY company_id DESC LIMIT 1");
        $st->execute([$companyId, $value]);
        $id = (int)$st->fetchColumn();
        if ($id || !$create) return $id;
        $db->prepare("INSERT IGNORE INTO email_tags (company_id, name, color) VALUES (?, ?, '#7b1e2b')")
           ->execute([$companyId, mb_substr($value, 0, 64)]);
        $st->execute([$companyId, $value]);
        return (int)$st->fetchColumn();
    }
}
