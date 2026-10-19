<?php
// crm/lib/CrmDedupe.php
//
// Finding and merging duplicate customers or suppliers.
//   - scan(): compares accounts of the same type in buckets (same email,
//     phone, VAT or registration number, or names that start alike) instead
//     of every pair, scores each match and keeps the candidates above the
//     company's threshold in crm_merge_candidates,
//   - merge(): one transaction that keeps the chosen account, takes the
//     chosen value for each field, and moves everything that points at the
//     other account to it — contacts, addresses, activity, tasks, deals,
//     compliance, tags, custom fields, emails, calendar links, quotes,
//     invoices, credit notes, recurring invoices, supplier bills, payments,
//     vendor credits, purchase orders, cost items, ledger lines, projects and
//     shopping stores — then soft-deletes the other account (restorable).

require_once __DIR__ . '/Crm.php';
require_once __DIR__ . '/CrmAccounts.php';
require_once __DIR__ . '/CrmFields.php';

final class CrmDedupe
{
    public const FIELDS = ['name', 'legal_name', 'reg_no', 'vat_no', 'email', 'phone', 'website', 'industry_id', 'region_id', 'status', 'owner_id', 'currency', 'notes'];

    /** Re-scan; returns ['scanned' => n, 'found' => n]. $type: customer | supplier | all */
    public static function scan(PDO $db, int $companyId, string $type = 'all'): array
    {
        $threshold = max(0.5, min(0.99, (float)Crm::rules($db, $companyId)['crm_duplicate_threshold']));
        $sql = "SELECT id, type, name, email, phone, vat_no, reg_no FROM crm_accounts WHERE company_id = ? AND deleted_at IS NULL";
        $params = [$companyId];
        if (in_array($type, Crm::TYPES, true)) { $sql .= " AND type = ?"; $params[] = $type; }
        $st = $db->prepare($sql . " ORDER BY id LIMIT 20000");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $buckets = [];
        foreach ($rows as $i => $r) {
            $norm = CrmAccounts::normName((string)$r['name']);
            $rows[$i]['norm'] = $norm;
            $t = $r['type'];
            $e = strtolower(trim((string)$r['email']));
            if ($e !== '') $buckets["$t|e|$e"][] = $i;
            $p = preg_replace('/\D+/', '', (string)$r['phone']);
            if (strlen($p) >= 9) $buckets["$t|p|" . substr($p, -9)][] = $i;
            $v = preg_replace('/\D+/', '', (string)$r['vat_no']);
            if (strlen($v) >= 8) $buckets["$t|v|$v"][] = $i;
            $g = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$r['reg_no']));
            if (strlen($g) >= 6) $buckets["$t|r|$g"][] = $i;
            if (mb_strlen($norm) >= 3) $buckets["$t|n|" . mb_substr($norm, 0, 3)][] = $i;
        }
        $pairs = [];
        foreach ($buckets as $members) {
            $n = count($members);
            if ($n < 2 || $n > 400) continue;
            for ($a = 0; $a < $n; $a++) for ($b = $a + 1; $b < $n; $b++) {
                $x = $members[$a]; $y = $members[$b];
                $pairs[min($x, $y) . ':' . max($x, $y)] = [$x, $y];
            }
        }
        $found = [];
        foreach ($pairs as [$x, $y]) {
            $l = $rows[$x]; $r = $rows[$y];
            [$score, $why] = self::score($l, $r);
            if ($score >= $threshold) $found[] = [(int)$l['id'], (int)$r['id'], min(1.0, $score), $why];
        }
        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM crm_merge_candidates WHERE company_id = ? AND resolved = 0")->execute([$companyId]);
            // pairs the company dismissed stay dismissed
            $dismissed = [];
            $d = $db->prepare("SELECT left_id, right_id FROM crm_merge_candidates WHERE company_id = ? AND resolved = 1");
            $d->execute([$companyId]);
            foreach ($d->fetchAll(PDO::FETCH_ASSOC) as $p) $dismissed[min($p['left_id'], $p['right_id']) . ':' . max($p['left_id'], $p['right_id'])] = true;
            $ins = $db->prepare("INSERT INTO crm_merge_candidates (company_id, left_id, right_id, match_score, reason, resolved, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())");
            $added = 0;
            foreach ($found as [$a, $b, $score, $why]) {
                if (isset($dismissed[min($a, $b) . ':' . max($a, $b)])) continue;
                $ins->execute([$companyId, $a, $b, round($score, 2), json_encode($why)]);
                $added++;
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        return ['scanned' => count($rows), 'found' => $added];
    }

    /** [score 0..1+, reasons[]] for two account rows (with 'norm'). */
    public static function score(array $l, array $r): array
    {
        $score = 0.0;
        $why = [];
        $e1 = strtolower(trim((string)$l['email'])); $e2 = strtolower(trim((string)$r['email']));
        if ($e1 !== '' && $e1 === $e2) { $score += 0.85; $why[] = 'Same email'; }
        $p1 = preg_replace('/\D+/', '', (string)$l['phone']); $p2 = preg_replace('/\D+/', '', (string)$r['phone']);
        if (strlen($p1) >= 9 && substr($p1, -9) === substr($p2, -9)) { $score += 0.6; $why[] = 'Same phone'; }
        $v1 = preg_replace('/\D+/', '', (string)$l['vat_no']); $v2 = preg_replace('/\D+/', '', (string)$r['vat_no']);
        if (strlen($v1) >= 8 && $v1 === $v2) { $score += 0.85; $why[] = 'Same VAT number'; }
        $g1 = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$l['reg_no'])); $g2 = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$r['reg_no']));
        if (strlen($g1) >= 6 && $g1 === $g2) { $score += 0.85; $why[] = 'Same registration number'; }
        $sim = CrmAccounts::nameSimilarity((string)($l['norm'] ?? CrmAccounts::normName((string)$l['name'])), (string)($r['norm'] ?? CrmAccounts::normName((string)$r['name'])));
        if ($sim >= 0.98) { $score += 0.9; $why[] = 'Same name'; }
        elseif ($sim >= 0.8) { $score += $sim * 0.8; $why[] = 'Similar name (' . (int)round($sim * 100) . '%)'; }
        return [$score, $why];
    }

    /** Open candidates with both accounts side by side. */
    public static function candidates(PDO $db, int $companyId): array
    {
        $st = $db->prepare("SELECT c.*, l.name AS left_name, r.name AS right_name, l.type
                              FROM crm_merge_candidates c
                              JOIN crm_accounts l ON l.id = c.left_id AND l.company_id = c.company_id AND l.deleted_at IS NULL
                              JOIN crm_accounts r ON r.id = c.right_id AND r.company_id = c.company_id AND r.deleted_at IS NULL
                             WHERE c.company_id = ? AND c.resolved = 0
                          ORDER BY c.match_score DESC, c.id LIMIT 500");
        $st->execute([$companyId]);
        return array_map(fn($c) => [
            'id' => (int)$c['id'], 'score' => (float)$c['match_score'], 'why' => json_decode((string)$c['reason'], true) ?: [],
            'type' => (string)$c['type'],
            'left' => ['id' => (int)$c['left_id'], 'name' => (string)$c['left_name']],
            'right' => ['id' => (int)$c['right_id'], 'name' => (string)$c['right_name']],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Both accounts with the counts of what hangs off them, for the merge dialog. */
    public static function compare(PDO $db, int $companyId, int $leftId, int $rightId): array
    {
        $out = [];
        $names = CrmAccounts::lookupNames($db, $companyId);
        foreach ([$leftId, $rightId] as $id) {
            $r = CrmAccounts::row($db, $companyId, $id);
            if (!$r) throw new CrmUserError('One of these accounts no longer exists.');
            $count = function (string $sql) use ($db, $companyId, $id) {
                try { $st = $db->prepare($sql); $st->execute([$companyId, $id]); return (int)$st->fetchColumn(); } catch (Throwable $e) { return 0; }
            };
            $out[] = [
                'id' => $id, 'type' => $r['type'], 'created_at' => $r['created_at'],
                'fields' => array_combine(self::FIELDS, array_map(fn($f) => $r[$f] ?? null, self::FIELDS)),
                'labels' => ['industry_id' => $names['industries'][(int)$r['industry_id']] ?? '', 'region_id' => $names['regions'][(int)$r['region_id']] ?? '',
                             'owner_id' => $r['owner_id'] ? Crm::personName($db, $companyId, (int)$r['owner_id']) : ''],
                'counts' => [
                    'contacts' => $count("SELECT COUNT(*) FROM crm_contacts WHERE company_id = ? AND account_id = ?"),
                    'activity' => $count("SELECT COUNT(*) FROM crm_interactions WHERE company_id = ? AND account_id = ?"),
                    'deals' => $count("SELECT COUNT(*) FROM crm_opportunities WHERE company_id = ? AND account_id = ?"),
                    'documents' => $count("SELECT (SELECT COUNT(*) FROM quotes WHERE company_id = ? AND customer_id = ?)") + $count("SELECT COUNT(*) FROM invoices WHERE company_id = ? AND customer_id = ?")
                                 + $count("SELECT COUNT(*) FROM ap_bills WHERE company_id = ? AND supplier_id = ?") + $count("SELECT COUNT(*) FROM purchase_orders WHERE company_id = ? AND supplier_id = ?"),
                ],
            ];
        }
        if ($out[0]['type'] !== $out[1]['type']) throw new CrmUserError('A customer and a supplier cannot be merged.');
        return $out;
    }

    /**
     * Merge $loserId into $keepId. $pick: [field => 'keep' | 'other'] (default keep, or the other side when keep is empty).
     */
    public static function merge(PDO $db, int $companyId, int $userId, int $keepId, int $loserId, array $pick, int $candidateId = 0): void
    {
        if ($keepId === $loserId) throw new CrmUserError('Choose two different accounts.');
        $keep = CrmAccounts::row($db, $companyId, $keepId);
        $lose = CrmAccounts::row($db, $companyId, $loserId);
        if (!$keep || !$lose) throw new CrmUserError('One of these accounts no longer exists.');
        if ($keep['type'] !== $lose['type']) throw new CrmUserError('A customer and a supplier cannot be merged.');
        if ($keep['deleted_at'] || $lose['deleted_at']) throw new CrmUserError('Restore deleted accounts before merging them.');

        $set = [];
        foreach (self::FIELDS as $f) {
            $side = $pick[$f] ?? null;
            $kv = $keep[$f] ?? null;
            $lv = $lose[$f] ?? null;
            if ($side === 'other' || ($side === null && ($kv === null || $kv === '') && $lv !== null && $lv !== '')) $set[$f] = $lv;
        }
        if (isset($set['notes']) === false && trim((string)$lose['notes']) !== '' && trim((string)$keep['notes']) !== '' && ($pick['notes'] ?? '') === 'both') {
            $set['notes'] = trim($keep['notes']) . "\n\n" . trim($lose['notes']);
        }
        $db->beginTransaction();
        try {
            // The loser steps out of the way first (names are unique per type); its own id keeps the new name unique too.
            $db->prepare("UPDATE crm_accounts SET deleted_at = NOW(), name = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
               ->execute([mb_substr($lose['name'], 0, 220) . ' (#' . $loserId . ' merged into #' . $keepId . ')', $loserId, $companyId]);
            if ($set) {
                $db->prepare("UPDATE crm_accounts SET " . implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($set))) . ", updated_at = NOW() WHERE id = ? AND company_id = ?")
                   ->execute(array_merge(array_values($set), [$keepId, $companyId]));
            }
            $db->prepare("UPDATE crm_contacts SET is_primary = 0 WHERE company_id = ? AND account_id = ?")->execute([$companyId, $loserId]);
            foreach (['crm_contacts', 'crm_addresses', 'crm_interactions', 'crm_compliance_docs', 'crm_opportunities', 'crm_tasks'] as $t) {
                $db->prepare("UPDATE `$t` SET account_id = ? WHERE company_id = ? AND account_id = ?")->execute([$keepId, $companyId, $loserId]);
            }
            $repoint = [
                ['quotes', 'customer_id'], ['invoices', 'customer_id'], ['credit_notes', 'customer_id'], ['recurring_invoices', 'customer_id'],
                ['journal_lines', 'customer_id'], ['journal_lines', 'supplier_id'],
                ['ap_bills', 'supplier_id'], ['ap_payments', 'supplier_id'], ['vendor_credits', 'supplier_id'], ['purchase_orders', 'supplier_id'],
                ['cost_items', 'supplier_id'], ['projects', 'client_id'], ['shopping_stores', 'crm_account_id'],
            ];
            foreach ($repoint as [$t, $col]) {
                try {
                    $hasCo = isset(CrmSchema::columns($db, $t)['company_id']);
                    if (!CrmSchema::columns($db, $t)) continue;
                    if ($hasCo) {
                        $db->prepare("UPDATE `$t` SET `$col` = ? WHERE `$col` = ? AND company_id = ?")->execute([$keepId, $loserId, $companyId]);
                    } else {
                        // journal_lines has no company_id: limit to lines of this company's journals
                        $db->prepare("UPDATE `$t` jl JOIN journal_entries je ON je.id = jl.journal_id AND je.company_id = ? SET jl.`$col` = ? WHERE jl.`$col` = ?")
                           ->execute([$companyId, $keepId, $loserId]);
                    }
                } catch (Throwable $e) {
                    error_log("[crm] merge repoint $t.$col: " . $e->getMessage());
                }
            }
            $db->prepare("INSERT IGNORE INTO crm_account_tags (account_id, tag_id) SELECT ?, tag_id FROM crm_account_tags WHERE account_id = ?")->execute([$keepId, $loserId]);
            $db->prepare("DELETE FROM crm_account_tags WHERE account_id = ?")->execute([$loserId]);
            CrmFields::move($db, $companyId, 'account', $loserId, $keepId);
            try {
                $db->prepare("UPDATE IGNORE email_links SET linked_id = ? WHERE linked_id = ? AND linked_type IN ('customer','supplier') AND (company_id = ? OR company_id IS NULL)")
                   ->execute([$keepId, $loserId, $companyId]);
                $db->prepare("DELETE FROM email_links WHERE linked_id = ? AND linked_type IN ('customer','supplier') AND (company_id = ? OR company_id IS NULL)")->execute([$loserId, $companyId]);
            } catch (Throwable $e) { }
            try {
                $db->prepare("UPDATE calendar_event_links l JOIN calendar_events e ON e.id = l.event_id AND e.company_id = ?
                                 SET l.linked_id = ? WHERE l.linked_type = 'crm_account' AND l.linked_id = ?")->execute([$companyId, $keepId, $loserId]);
            } catch (Throwable $e) { }
            $db->prepare("UPDATE crm_merge_candidates SET resolved = 1 WHERE company_id = ? AND (left_id IN (?, ?) OR right_id IN (?, ?))")
               ->execute([$companyId, $keepId, $loserId, $keepId, $loserId]);
            Crm::audit($db, $companyId, $userId, 'merge', 'crm_account', $keepId, ['merged' => $loserId, 'merged_name' => $lose['name'], 'fields' => array_keys($set), 'candidate' => $candidateId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($e instanceof PDOException && str_contains($e->getMessage(), '1062')) throw new CrmUserError('Another account already has the chosen name.');
            throw $e;
        }
    }

    public static function dismiss(PDO $db, int $companyId, int $candidateId): void
    {
        $st = $db->prepare("UPDATE crm_merge_candidates SET resolved = 1 WHERE id = ? AND company_id = ?");
        $st->execute([$candidateId, $companyId]);
        if ($st->rowCount() === 0) throw new CrmUserError('This suggestion is no longer open.');
    }
}
