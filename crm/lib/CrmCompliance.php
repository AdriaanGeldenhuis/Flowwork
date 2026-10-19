<?php
// crm/lib/CrmCompliance.php
//
// Supplier (and customer) compliance documents: tax clearance, B-BBEE
// certificates, insurance and whatever types the company defines.
//   - status is worked out from the expiry date every time (the stored status
//     column is refreshed too, because Receipts reads it when approving bills),
//   - required types that an account has no document for count as missing,
//   - the company can block suppliers with missing or expired documents,
//   - files live in uploads/compliance/ (denied to the web) under random names
//     and are published to FlowWork Drive; downloads go through the CRM API,
//     which also serves the files suppliers upload through their portal.

require_once __DIR__ . '/Crm.php';

final class CrmCompliance
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const TYPES = [
        'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
    ];

    /** Company policy: block suppliers, reminder days (descending), the "expiring" window. */
    public static function policy(PDO $db, int $companyId): array
    {
        $r = Crm::rules($db, $companyId);
        $days = array_values(array_unique(array_filter(array_map('intval', preg_split('/[,;\s]+/', (string)$r['crm_reminder_days'])), fn($d) => $d > 0 && $d <= 365)));
        rsort($days);
        return [
            'block' => $r['crm_block_expired_suppliers'] === '1',
            'reminder_days' => $days ?: [30, 14, 7],
            'window' => $days ? max($days) : 30,
            'badge' => $r['crm_compliance_badge_enable'] !== '0',
        ];
    }

    public static function types(PDO $db, int $companyId): array
    {
        $st = $db->prepare("SELECT t.*, (SELECT COUNT(*) FROM crm_compliance_docs d WHERE d.company_id = t.company_id AND d.type_id = t.id) AS docs
                              FROM crm_compliance_types t WHERE t.company_id = ? ORDER BY t.required DESC, t.name");
        $st->execute([$companyId]);
        return array_map(fn($t) => [
            'id' => (int)$t['id'], 'code' => (string)$t['code'], 'name' => (string)$t['name'],
            'expiry_months' => $t['default_expiry_months'] !== null ? (int)$t['default_expiry_months'] : null,
            'required' => (bool)$t['required'], 'docs' => (int)$t['docs'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Live status of one document: valid | expiring | expired. */
    public static function docStatus(?string $expiry, int $window): string
    {
        if (!$expiry || $expiry < '1000-01-02') return 'valid';
        $today = date('Y-m-d');
        if ($expiry < $today) return 'expired';
        if ($expiry <= date('Y-m-d', strtotime("+$window days"))) return 'expiring';
        return 'valid';
    }

    /**
     * The overall state of an account.
     * @return array{status: string, blocking: bool, missing: string[], expiring: string[], expired: string[], required: int, docs: int}
     */
    public static function state(PDO $db, int $companyId, int $accountId, string $accountType): array
    {
        return self::states($db, $companyId, [$accountId => $accountType])[$accountId];
    }

    /**
     * States for several accounts at once ([id => type]).
     * status: none (nothing required, no documents) | valid | expiring | expired | missing
     */
    public static function states(PDO $db, int $companyId, array $accounts): array
    {
        $policy = self::policy($db, $companyId);
        $out = [];
        foreach ($accounts as $id => $type) {
            $out[(int)$id] = ['status' => 'none', 'blocking' => false, 'missing' => [], 'expiring' => [], 'expired' => [], 'required' => 0, 'docs' => 0];
        }
        if (!$out) return $out;
        $types = [];
        try {
            $st = $db->prepare("SELECT id, name, required FROM crm_compliance_types WHERE company_id = ?");
            $st->execute([$companyId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) $types[(int)$t['id']] = $t;
            $ids = implode(',', array_keys($out));
            $st = $db->prepare("SELECT account_id, type_id, MAX(COALESCE(expiry_date, '9999-12-31')) AS best, COUNT(*) AS n
                                  FROM crm_compliance_docs WHERE company_id = ? AND account_id IN ($ids) GROUP BY account_id, type_id");
            $st->execute([$companyId]);
            $have = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) $have[(int)$d['account_id']][(int)$d['type_id']] = $d;
        } catch (Throwable $e) {
            return $out;
        }
        foreach ($out as $id => &$s) {
            $type = $accounts[$id];
            foreach ($have[$id] ?? [] as $typeId => $d) {
                $s['docs'] += (int)$d['n'];
                $best = $d['best'] === '9999-12-31' ? null : $d['best'];
                $name = $types[$typeId]['name'] ?? 'Document';
                $st = self::docStatus($best, $policy['window']);
                if ($st === 'expired') $s['expired'][] = $name;
                elseif ($st === 'expiring') $s['expiring'][] = $name;
            }
            // Required types apply to suppliers (what the policy and the portal ask of them).
            if ($type === 'supplier') {
                foreach ($types as $typeId => $t) {
                    if (!(int)$t['required']) continue;
                    $s['required']++;
                    if (!isset($have[$id][$typeId])) $s['missing'][] = $t['name'];
                }
            }
            if ($s['missing']) $s['status'] = 'missing';
            elseif ($s['expired']) $s['status'] = 'expired';
            elseif ($s['expiring']) $s['status'] = 'expiring';
            elseif ($s['docs'] > 0 || $s['required'] > 0) $s['status'] = 'valid';
            $s['blocking'] = $type === 'supplier' && $policy['block'] && in_array($s['status'], ['missing', 'expired'], true);
        }
        unset($s);
        return $out;
    }

    /** Documents of an account with their live status. */
    public static function docs(PDO $db, int $companyId, int $accountId): array
    {
        $policy = self::policy($db, $companyId);
        $st = $db->prepare("SELECT d.*, t.name AS type_name, t.code AS type_code, t.required,
                                   TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS by_name
                              FROM crm_compliance_docs d JOIN crm_compliance_types t ON t.id = d.type_id AND t.company_id = d.company_id
                         LEFT JOIN users u ON u.id = d.created_by
                             WHERE d.company_id = ? AND d.account_id = ?
                          ORDER BY (d.expiry_date IS NULL), d.expiry_date, t.name");
        $st->execute([$companyId, $accountId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $status = self::docStatus($d['expiry_date'], $policy['window']);
            $days = $d['expiry_date'] ? (int)floor((strtotime($d['expiry_date']) - strtotime(date('Y-m-d'))) / 86400) : null;
            $out[] = [
                'id' => (int)$d['id'], 'type_id' => (int)$d['type_id'], 'type' => (string)$d['type_name'], 'code' => (string)$d['type_code'],
                'required' => (bool)$d['required'], 'reference' => (string)($d['reference_no'] ?? ''),
                'issue_date' => $d['issue_date'], 'expiry_date' => $d['expiry_date'], 'days' => $days, 'status' => $status,
                'notes' => (string)($d['notes'] ?? ''), 'has_file' => !empty($d['file_path']),
                'file_name' => (string)($d['original_name'] ?? '') ?: ($d['file_path'] ? basename((string)$d['file_path']) : ''),
                'by' => (string)$d['by_name'], 'created_at' => $d['created_at'],
                'verified' => !empty($d['verified_at']),
            ];
        }
        return $out;
    }

    /** Save a document (create or update), with an optional uploaded file ($_FILES entry). Returns the id. */
    public static function saveDoc(PDO $db, int $companyId, int $userId, array $in, ?array $file): int
    {
        $docId = (int)($in['id'] ?? 0);
        $accountId = (int)($in['account_id'] ?? 0);
        $typeId = (int)($in['type_id'] ?? 0);
        $acc = $db->prepare("SELECT id, type FROM crm_accounts WHERE id = ? AND company_id = ?");
        $acc->execute([$accountId, $companyId]);
        if (!$acc->fetch()) throw new CrmUserError('Account not found.');
        $t = $db->prepare("SELECT id, default_expiry_months FROM crm_compliance_types WHERE id = ? AND company_id = ?");
        $t->execute([$typeId, $companyId]);
        $type = $t->fetch(PDO::FETCH_ASSOC);
        if (!$type) throw new CrmUserError('Choose a document type.');
        $ref = mb_substr(trim((string)($in['reference'] ?? '')), 0, 190);
        $issue = trim((string)($in['issue_date'] ?? '')) !== '' ? Crm::ymd($in['issue_date']) : null;
        if (trim((string)($in['issue_date'] ?? '')) !== '' && $issue === null) throw new CrmUserError('The issue date is not a valid date.');
        $expiry = trim((string)($in['expiry_date'] ?? '')) !== '' ? Crm::ymd($in['expiry_date']) : null;
        if (trim((string)($in['expiry_date'] ?? '')) !== '' && $expiry === null) throw new CrmUserError('The expiry date is not a valid date.');
        if ($expiry === null && $issue !== null && !empty($in['auto_expiry']) && (int)$type['default_expiry_months'] > 0) {
            $expiry = date('Y-m-d', strtotime($issue . ' +' . (int)$type['default_expiry_months'] . ' months'));
        }
        if ($issue && $expiry && $expiry < $issue) throw new CrmUserError('The expiry date is before the issue date.');
        $notes = mb_substr(trim((string)($in['notes'] ?? '')), 0, 2000);
        $policy = self::policy($db, $companyId);
        $status = self::docStatus($expiry, $policy['window']);

        $old = null;
        if ($docId > 0) {
            $st = $db->prepare("SELECT * FROM crm_compliance_docs WHERE id = ? AND company_id = ?");
            $st->execute([$docId, $companyId]);
            $old = $st->fetch(PDO::FETCH_ASSOC);
            if (!$old) throw new CrmUserError('Document not found.');
            if ((int)$old['account_id'] !== $accountId) throw new CrmUserError('Document not found.');
        }

        $stored = null;
        if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $stored = self::storeUpload($file);
        } elseif (!$old && !empty($in['require_file'])) {
            throw new CrmUserError('Attach the document.');
        }

        if ($old) {
            $db->prepare("UPDATE crm_compliance_docs SET type_id = ?, reference_no = ?, issue_date = ?, expiry_date = ?, notes = ?, status = ?"
                         . ($stored ? ", file_path = ?, original_name = ?" : '') . " WHERE id = ? AND company_id = ?")
               ->execute(array_merge([$typeId, $ref ?: null, $issue, $expiry, $notes ?: null, $status],
                                     $stored ? [$stored['path'], $stored['name']] : [], [$docId, $companyId]));
        } else {
            $db->prepare("INSERT INTO crm_compliance_docs (company_id, account_id, type_id, reference_no, issue_date, expiry_date, file_path, original_name, notes, status, created_by, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
               ->execute([$companyId, $accountId, $typeId, $ref ?: null, $issue, $expiry, $stored['path'] ?? null, $stored['name'] ?? null, $notes ?: null, $status, $userId]);
            $docId = (int)$db->lastInsertId();
        }

        // FlowWork Drive copy (best-effort): from the new file, or republished
        // from the existing one so a renamed reference renames the copy too.
        $publish = $stored ? $stored['abs'] : ($old && $old['file_path'] ? self::absPath($companyId, (string)$old['file_path']) : null);
        if ($publish) {
            try {
                require_once __DIR__ . '/../../includes/flowdrive/FlowDriveSync.php';
                FlowDriveSync::fileComplianceDoc($db, $companyId, $accountId, $typeId, $ref, $publish, $userId, $docId);
            } catch (Throwable $e) {
                error_log('[crm] compliance drive copy: ' . $e->getMessage());
            }
        }
        // A replaced file is removed from disk once the row points at the new one.
        if ($stored && $old && $old['file_path']) {
            $prev = self::absPath($companyId, (string)$old['file_path']);
            if ($prev && $prev !== $stored['abs']) @unlink($prev);
        }
        Crm::audit($db, $companyId, $userId, $old ? 'crm_compliance_update' : 'crm_compliance_create', 'crm_compliance_doc', $docId,
                   ['account_id' => $accountId, 'type_id' => $typeId, 'file' => (bool)$stored]);
        return $docId;
    }

    public static function deleteDoc(PDO $db, int $companyId, int $userId, int $docId): void
    {
        $st = $db->prepare("SELECT * FROM crm_compliance_docs WHERE id = ? AND company_id = ?");
        $st->execute([$docId, $companyId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc) throw new CrmUserError('Document not found.');
        $db->prepare("DELETE FROM crm_compliance_docs WHERE id = ? AND company_id = ?")->execute([$docId, $companyId]);
        if ($doc['file_path']) {
            $abs = self::absPath($companyId, (string)$doc['file_path']);
            if ($abs) @unlink($abs);
            try {
                require_once __DIR__ . '/../../includes/flowdrive/FlowDriveSync.php';
                FlowDriveSync::removeComplianceDocById($db, $companyId, $docId);
                FlowDriveSync::removeComplianceDoc($db, $companyId, (int)$doc['account_id'], (int)$doc['type_id'],
                                                   (string)($doc['reference_no'] ?? ''), pathinfo((string)$doc['file_path'], PATHINFO_EXTENSION));
            } catch (Throwable $e) {
                error_log('[crm] compliance drive remove: ' . $e->getMessage());
            }
        }
        Crm::audit($db, $companyId, $userId, 'crm_compliance_delete', 'crm_compliance_doc', $docId,
                   ['account_id' => (int)$doc['account_id'], 'type_id' => (int)$doc['type_id']]);
    }

    /** [absolute path, download name, mime] for a document of this company, or null. */
    public static function download(PDO $db, int $companyId, int $docId): ?array
    {
        $st = $db->prepare("SELECT d.file_path, d.original_name, d.reference_no, t.name AS type_name, a.name AS account_name
                              FROM crm_compliance_docs d
                         LEFT JOIN crm_compliance_types t ON t.id = d.type_id
                         LEFT JOIN crm_accounts a ON a.id = d.account_id
                             WHERE d.id = ? AND d.company_id = ?");
        $st->execute([$docId, $companyId]);
        $d = $st->fetch(PDO::FETCH_ASSOC);
        if (!$d || !$d['file_path']) return null;
        $abs = self::absPath($companyId, (string)$d['file_path']);
        if (!$abs || !is_file($abs)) return null;
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) return null;
        $base = trim(preg_replace('/[^A-Za-z0-9 _.-]+/', '', ($d['account_name'] ?? '') . ' ' . ($d['type_name'] ?? 'Document') . ($d['reference_no'] ? ' ' . $d['reference_no'] : '')));
        return [$abs, ($base !== '' ? $base : 'document') . '.' . $ext, self::TYPES[$ext]];
    }

    /**
     * Resolve a stored file_path to a file inside this company's allowed folders
     * (uploads/compliance, or the portal's uploads/company/<id>/compliance).
     */
    public static function absPath(int $companyId, string $stored): ?string
    {
        $root = realpath(dirname(__DIR__, 2));
        if ($root === false || $stored === '') return null;
        $abs = realpath($root . '/' . ltrim($stored, '/'));
        if ($abs === false) return null;
        foreach ([$root . '/uploads/compliance', $root . '/uploads/company/' . $companyId . '/compliance'] as $dir) {
            $base = realpath($dir);
            if ($base !== false && str_starts_with($abs, $base . DIRECTORY_SEPARATOR)) return $abs;
        }
        return null;
    }

    /** Validate and keep an upload. @return array{path: string, abs: string, name: string} */
    /** Validate and store an uploaded document privately (also used by the supplier portal). @return array{path: string, abs: string, name: string} */
    public static function storeUpload(array $file): array
    {
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new CrmUserError('The file is too large (10 MB at most).');
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) throw new CrmUserError('The file did not upload. Please try again.');
        if ((int)$file['size'] > self::MAX_BYTES) throw new CrmUserError('The file is too large (10 MB at most).');
        if ((int)$file['size'] <= 0) throw new CrmUserError('The file is empty.');
        $name = (string)($file['name'] ?? 'document');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) throw new CrmUserError('Upload a PDF or an image (JPG, PNG or WebP).');
        $mime = '';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $mime = (string)finfo_file($fi, (string)$file['tmp_name']);
            finfo_close($fi);
        }
        $okMime = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
        if ($mime !== '' && !in_array($mime, $okMime, true)) throw new CrmUserError('That file is not a PDF or an image.');
        $dir = dirname(__DIR__, 2) . '/uploads/compliance';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('compliance upload folder missing');
        $stored = 'comp_' . bin2hex(random_bytes(16)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $stored)) throw new RuntimeException('could not move the upload');
        @chmod($dir . '/' . $stored, 0640);
        $clean = mb_substr(preg_replace('/[^\p{L}\p{N} _.()-]+/u', '', basename($name)) ?: 'document.' . $ext, 0, 200);
        return ['path' => 'uploads/compliance/' . $stored, 'abs' => $dir . '/' . $stored, 'name' => $clean];
    }

    /** Keep the stored status in step with the expiry dates (Receipts reads it). Cheap, once a day per company. */
    public static function refreshStored(PDO $db, int $companyId): void
    {
        $flag = dirname(__DIR__, 2) . '/storage/crm/.compliance-' . $companyId . '-' . date('Ymd');
        if (is_file($flag)) return;
        $window = self::policy($db, $companyId)['window'];
        try {
            $db->prepare("UPDATE crm_compliance_docs
                             SET status = CASE WHEN expiry_date IS NULL THEN 'valid'
                                               WHEN expiry_date < CURDATE() THEN 'expired'
                                               WHEN expiry_date <= CURDATE() + INTERVAL ? DAY THEN 'expiring'
                                               ELSE 'valid' END,
                                 updated_at = updated_at
                           WHERE company_id = ? AND status <> 'missing'")
               ->execute([$window, $companyId]);
            @touch($flag);
        } catch (Throwable $e) {
            error_log('[crm] compliance refresh: ' . $e->getMessage());
        }
    }

    /** The company-wide picture for the Compliance view. */
    public static function overview(PDO $db, int $companyId): array
    {
        self::refreshStored($db, $companyId);
        $policy = self::policy($db, $companyId);
        $st = $db->prepare("SELECT id, name, type, status FROM crm_accounts WHERE company_id = ? AND deleted_at IS NULL AND (type = 'supplier' OR id IN (SELECT account_id FROM crm_compliance_docs WHERE company_id = ?))");
        $st->execute([$companyId, $companyId]);
        $accounts = $st->fetchAll(PDO::FETCH_ASSOC);
        $states = self::states($db, $companyId, array_column($accounts, 'type', 'id'));
        $rows = [];
        $counts = ['valid' => 0, 'expiring' => 0, 'expired' => 0, 'missing' => 0, 'none' => 0, 'blocked' => 0];
        foreach ($accounts as $a) {
            $s = $states[(int)$a['id']];
            $counts[$s['status']]++;
            if ($s['blocking']) $counts['blocked']++;
            $rows[] = ['id' => (int)$a['id'], 'name' => $a['name'], 'type' => $a['type'], 'account_status' => $a['status']] + $s;
        }
        $rank = ['missing' => 0, 'expired' => 1, 'expiring' => 2, 'valid' => 3, 'none' => 4];
        usort($rows, fn($x, $y) => [$rank[$x['status']], $x['name']] <=> [$rank[$y['status']], $y['name']]);

        $st = $db->prepare("SELECT d.id, d.account_id, d.expiry_date, d.reference_no, t.name AS type_name, a.name AS account_name
                              FROM crm_compliance_docs d JOIN crm_compliance_types t ON t.id = d.type_id
                              JOIN crm_accounts a ON a.id = d.account_id AND a.deleted_at IS NULL
                             WHERE d.company_id = ? AND d.expiry_date BETWEEN CURDATE() - INTERVAL 90 DAY AND CURDATE() + INTERVAL ? DAY
                               AND NOT EXISTS (SELECT 1 FROM crm_compliance_docs n WHERE n.company_id = d.company_id AND n.account_id = d.account_id
                                                  AND n.type_id = d.type_id AND n.id <> d.id AND COALESCE(n.expiry_date, '9999-12-31') > d.expiry_date)
                          ORDER BY d.expiry_date LIMIT 200");
        $st->execute([$companyId, $policy['window']]);
        $soon = array_map(fn($d) => [
            'id' => (int)$d['id'], 'account_id' => (int)$d['account_id'], 'account' => $d['account_name'], 'type' => $d['type_name'],
            'reference' => (string)($d['reference_no'] ?? ''), 'expiry_date' => $d['expiry_date'],
            'days' => (int)floor((strtotime($d['expiry_date']) - strtotime(date('Y-m-d'))) / 86400),
            'status' => self::docStatus($d['expiry_date'], $policy['window']),
        ], $st->fetchAll(PDO::FETCH_ASSOC));
        return ['policy' => $policy, 'counts' => $counts, 'accounts' => $rows, 'soon' => $soon, 'types' => self::types($db, $companyId)];
    }

    // ------------------------------------------------------------------ types

    public static function saveType(PDO $db, int $companyId, array $in): int
    {
        $id = (int)($in['id'] ?? 0);
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
        if ($name === '') throw new CrmUserError('Give the document type a name.');
        $code = strtoupper(preg_replace('/[^A-Za-z0-9_]+/', '_', trim((string)($in['code'] ?? '')) ?: $name));
        $code = substr(trim($code, '_'), 0, 32) ?: 'DOC';
        $months = trim((string)($in['expiry_months'] ?? '')) === '' ? null : max(0, min(120, (int)$in['expiry_months']));
        $required = !empty($in['required']) ? 1 : 0;
        $dup = $db->prepare("SELECT id FROM crm_compliance_types WHERE company_id = ? AND (code = ? OR name = ?) AND id <> ?");
        $dup->execute([$companyId, $code, $name, $id]);
        if ($dup->fetchColumn()) throw new CrmUserError('There is already a document type with this name or code.');
        if ($id > 0) {
            $st = $db->prepare("UPDATE crm_compliance_types SET code = ?, name = ?, default_expiry_months = ?, required = ? WHERE id = ? AND company_id = ?");
            $st->execute([$code, $name, $months, $required, $id, $companyId]);
            if ($st->rowCount() === 0) {
                $chk = $db->prepare("SELECT 1 FROM crm_compliance_types WHERE id = ? AND company_id = ?");
                $chk->execute([$id, $companyId]);
                if (!$chk->fetchColumn()) throw new CrmUserError('Document type not found.');
            }
            return $id;
        }
        $db->prepare("INSERT INTO crm_compliance_types (company_id, code, name, default_expiry_months, required) VALUES (?, ?, ?, ?, ?)")
           ->execute([$companyId, $code, $name, $months, $required]);
        return (int)$db->lastInsertId();
    }

    public static function deleteType(PDO $db, int $companyId, int $id): void
    {
        $n = $db->prepare("SELECT COUNT(*) FROM crm_compliance_docs WHERE company_id = ? AND type_id = ?");
        $n->execute([$companyId, $id]);
        $count = (int)$n->fetchColumn();
        if ($count > 0) throw new CrmUserError("{$count} " . ($count === 1 ? 'document uses' : 'documents use') . ' this type. Remove them first, or switch “required” off instead.');
        $db->prepare("DELETE FROM crm_compliance_types WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
    }

    /** Built-in South African starter set, added once when a company has no types. */
    public static function seedTypes(PDO $db, int $companyId): void
    {
        $n = $db->prepare("SELECT COUNT(*) FROM crm_compliance_types WHERE company_id = ?");
        $n->execute([$companyId]);
        if ((int)$n->fetchColumn() > 0) return;
        $ins = $db->prepare("INSERT INTO crm_compliance_types (company_id, code, name, default_expiry_months, required) VALUES (?, ?, ?, ?, ?)");
        foreach ([['TAX', 'Tax clearance (SARS PIN)', 12, 1], ['BBBEE', 'B-BBEE certificate', 12, 0], ['COID', 'COID letter of good standing', 12, 0],
                  ['BANK', 'Bank confirmation letter', null, 1], ['CIPC', 'Company registration (CIPC)', null, 0], ['INS', 'Public liability insurance', 12, 0]] as $t) {
            $ins->execute([$companyId, $t[0], $t[1], $t[2], $t[3]]);
        }
    }
}
