<?php
/**
 * FlowDriveSync — publishes Flowwork documents into "FlowWork Drive"
 * (flowdrive.co.za), organised per customer/supplier:
 *
 *   Customers/{Account Name}/Invoices/INV2026-0011.pdf
 *   Customers/{Account Name}/Quotes/Q2026-0005.pdf
 *   Customers/{Account Name}/Credit Notes/CN2026-0001.pdf
 *   Customers/{Account Name}/Documents/{uploaded files}
 *   Suppliers/{Account Name}/Documents/{uploaded files}
 *
 * Account folders carry meta_json {"fw_account_id": N} so they survive account
 * renames (the folder is found by id and renamed to match) and name clashes
 * (a second account with the same name gets "Name (N)").
 *
 * All methods are best-effort and never throw — see FlowDriveRepo.
 */
require_once __DIR__ . '/FlowDriveRepo.php';

class FlowDriveSync
{
    /** Last error surfaced by a publish call — read by the diagnostic. */
    public static $lastError = null;

    const CAT_INVOICES     = 'Invoices';
    const CAT_QUOTES       = 'Quotes';
    const CAT_CREDIT_NOTES = 'Credit Notes';
    const CAT_DOCUMENTS    = 'Documents';

    /**
     * Publish (create or replace) a document under the account's category
     * folder. Returns the fd_nodes id, or null if publishing was skipped.
     */
    public static function fileAccountDocument(
        PDO $db,
        int $companyId,
        int $accountId,
        string $category,
        string $filename,
        string $bytes,
        string $mime = 'application/pdf',
        ?int $userId = null
    ): ?int {
        try {
            if (!FlowDriveRepo::available($db)) {
                return null;
            }
            return FlowDriveRepo::withCompanyLock($db, $companyId, function () use ($db, $companyId, $accountId, $category, $filename, $bytes, $mime, $userId) {
                $folderId = self::ensureCategoryFolder($db, $companyId, $accountId, $category);
                if ($folderId === null) {
                    return null;
                }
                [$driveId] = self::driveFor($db, $companyId);
                return FlowDriveRepo::putFile($db, $driveId, $folderId, self::sanitizeFilename($filename), $bytes, $mime, $userId);
            });
        } catch (Throwable $e) {
            self::$lastError = 'fileAccountDocument: ' . $e->getMessage()
                . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']';
            error_log('FlowDriveSync::' . self::$lastError);
            return null;
        }
    }

    /**
     * Publish an uploaded compliance document under the account's Documents
     * folder, named "{Type name} - {reference|id}.{ext}". When $docId (the
     * crm_compliance_docs id) is given, the node is tagged with it in
     * meta_json and any OTHER node carrying that tag is soft-deleted first —
     * so renaming the reference/type (or moving accounts) replaces the drive
     * copy instead of orphaning the old one. Never throws.
     */
    public static function fileComplianceDoc(
        PDO $db,
        int $companyId,
        int $accountId,
        int $typeId,
        string $reference,
        string $absPath,
        ?int $userId = null,
        ?int $docId = null
    ): ?int {
        try {
            if (!is_file($absPath)) {
                return null;
            }
            $bytes = @file_get_contents($absPath);
            if ($bytes === false || $bytes === '') {
                return null;
            }

            $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
            $filename = self::complianceFilename($db, $companyId, $typeId, $reference, $ext);

            $mimeMap = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
            $mime = $mimeMap[$ext] ?? 'application/octet-stream';
            if (function_exists('mime_content_type')) {
                $detected = @mime_content_type($absPath);
                if (is_string($detected) && $detected !== '') {
                    $mime = $detected;
                }
            }

            // Retire any previous drive copy of THIS document first (identity
            // match, immune to renames). putFile below restores the node if
            // the name is unchanged, so remove-then-publish is safe always.
            if ($docId !== null) {
                self::removeComplianceDocById($db, $companyId, $docId);
            }

            if (!FlowDriveRepo::available($db)) {
                return null;
            }
            return FlowDriveRepo::withCompanyLock($db, $companyId, function () use ($db, $companyId, $accountId, $filename, $bytes, $mime, $userId, $docId) {
                $folderId = self::ensureCategoryFolder($db, $companyId, $accountId, self::CAT_DOCUMENTS);
                if ($folderId === null) {
                    return null;
                }
                [$driveId] = self::driveFor($db, $companyId);
                $meta = $docId !== null ? ['fw_compliance_doc_id' => $docId] : null;
                return FlowDriveRepo::putFile($db, $driveId, $folderId, self::sanitizeFilename($filename), $bytes, $mime, $userId, $meta);
            });
        } catch (Throwable $e) {
            error_log('FlowDriveSync::fileComplianceDoc: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Soft-delete every drive node tagged with this crm_compliance_docs id —
     * matching by identity, so it works regardless of type renames or
     * reference changes since publishing. Never throws.
     */
    public static function removeComplianceDocById(PDO $db, int $companyId, int $docId): void
    {
        try {
            if (!FlowDriveRepo::available($db)) {
                return;
            }
            $stmt = $db->prepare(
                "UPDATE fd_nodes n
                   JOIN fd_drives d ON n.drive_id = d.id
                    SET n.deleted_at = NOW()
                  WHERE d.company_id = ? AND d.type = 'company' AND d.subtype = 'flowwork'
                    AND n.type = 'file' AND n.deleted_at IS NULL
                    AND JSON_EXTRACT(n.meta_json, '$.fw_compliance_doc_id') = ?"
            );
            $stmt->execute([$companyId, $docId]);
        } catch (Throwable $e) {
            error_log('FlowDriveSync::removeComplianceDocById: ' . $e->getMessage());
        }
    }

    /**
     * Remove a compliance document's drive copy (the source row/file was
     * deleted, or its file is being replaced). $ext comes from the stored
     * file_path so the name matches what fileComplianceDoc published.
     */
    public static function removeComplianceDoc(
        PDO $db,
        int $companyId,
        int $accountId,
        int $typeId,
        string $reference,
        string $ext
    ): void {
        try {
            $filename = self::complianceFilename($db, $companyId, $typeId, $reference, strtolower($ext));
            self::removeAccountDocument($db, $companyId, $accountId, self::CAT_DOCUMENTS, $filename);
        } catch (Throwable $e) {
            error_log('FlowDriveSync::removeComplianceDoc: ' . $e->getMessage());
        }
    }

    /**
     * The drive filename for a compliance doc: "{Type name} - {reference}.{ext}".
     * Shared by publish and remove so they always agree.
     */
    private static function complianceFilename(PDO $db, int $companyId, int $typeId, string $reference, string $ext): string
    {
        $typeName = '';
        try {
            $stmt = $db->prepare("SELECT name FROM crm_compliance_types WHERE id = ? AND company_id = ?");
            $stmt->execute([$typeId, $companyId]);
            $typeName = (string)($stmt->fetchColumn() ?: '');
        } catch (Throwable $e) {
            // fall back to the generic label below
        }
        $label = self::sanitizeName($typeName) ?: 'Compliance Document';
        $ref   = self::sanitizeName($reference);
        return $label . ($ref !== '' ? ' - ' . $ref : '') . ($ext !== '' ? '.' . $ext : '');
    }

    /**
     * Soft-delete a previously published document (source was deleted).
     */
    public static function removeAccountDocument(
        PDO $db,
        int $companyId,
        int $accountId,
        string $category,
        string $filename
    ): void {
        try {
            if (!FlowDriveRepo::available($db)) {
                return;
            }
            $folderId = self::findCategoryFolder($db, $companyId, $accountId, $category);
            if ($folderId === null) {
                return;
            }
            [$driveId] = self::driveFor($db, $companyId);
            FlowDriveRepo::removeFile($db, $driveId, $folderId, self::sanitizeFilename($filename));
        } catch (Throwable $e) {
            error_log('FlowDriveSync::removeAccountDocument: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // internals
    // ------------------------------------------------------------------

    /** @var array<int,int> per-request cache: companyId => driveId */
    private static $driveCache = [];

    /** @return array{0:?int} */
    private static function driveFor(PDO $db, int $companyId): array
    {
        if (!isset(self::$driveCache[$companyId])) {
            self::$driveCache[$companyId] = FlowDriveRepo::ensureDrive($db, $companyId);
        }
        return [self::$driveCache[$companyId]];
    }

    /**
     * Resolve Customers|Suppliers / {Account} / {Category}, creating any
     * missing folders. Returns the category folder node id or null.
     */
    private static function ensureCategoryFolder(PDO $db, int $companyId, int $accountId, string $category): ?int
    {
        $account = self::loadAccount($db, $companyId, $accountId);
        if (!$account) {
            return null;
        }
        [$driveId] = self::driveFor($db, $companyId);
        if (!$driveId) {
            return null;
        }

        $topName  = $account['type'] === 'supplier' ? 'Suppliers' : 'Customers';
        $topId    = FlowDriveRepo::ensureFolder($db, $driveId, null, $topName);
        if ($topId === null) {
            return null;
        }

        $accFolderId = self::ensureAccountFolder($db, $driveId, $topId, $accountId, $account['name']);
        if ($accFolderId === null) {
            return null;
        }

        return FlowDriveRepo::ensureFolder($db, $driveId, $accFolderId, $category);
    }

    /** Like ensureCategoryFolder but never creates anything. */
    private static function findCategoryFolder(PDO $db, int $companyId, int $accountId, string $category): ?int
    {
        $account = self::loadAccount($db, $companyId, $accountId);
        if (!$account) {
            return null;
        }
        [$driveId] = self::driveFor($db, $companyId);
        if (!$driveId) {
            return null;
        }
        $topName = $account['type'] === 'supplier' ? 'Suppliers' : 'Customers';

        foreach (FlowDriveRepo::childFolders($db, $driveId, null) as $top) {
            if ($top['name'] !== $topName || $top['deleted_at'] !== null) {
                continue;
            }
            foreach (FlowDriveRepo::childFolders($db, $driveId, (int)$top['id']) as $acc) {
                $meta = $acc['meta_json'] ? json_decode($acc['meta_json'], true) : null;
                if (is_array($meta) && (int)($meta['fw_account_id'] ?? 0) === $accountId) {
                    foreach (FlowDriveRepo::childFolders($db, $driveId, (int)$acc['id']) as $cat) {
                        if ($cat['name'] === $category && $cat['deleted_at'] === null) {
                            return (int)$cat['id'];
                        }
                    }
                }
            }
        }
        return null;
    }

    /**
     * Find the account's folder by its fw_account_id meta (rename-aware),
     * else create it. Name clashes with a different account resolve to
     * "Name (accountId)".
     */
    private static function ensureAccountFolder(PDO $db, int $driveId, int $topId, int $accountId, string $accountName): ?int
    {
        $wanted   = self::sanitizeName($accountName) ?: ('Account ' . $accountId);
        $siblings = FlowDriveRepo::childFolders($db, $driveId, $topId);

        $mine = null;
        $nameTakenByOther = false;
        foreach ($siblings as $sib) {
            $meta  = $sib['meta_json'] ? json_decode($sib['meta_json'], true) : null;
            $sibId = is_array($meta) ? (int)($meta['fw_account_id'] ?? 0) : 0;
            if ($sibId === $accountId) {
                if ($mine === null) {
                    $mine = $sib;
                }
            } elseif ($sibId !== 0 && self::nameKey($sib['name']) === self::nameKey($wanted) && $sib['deleted_at'] === null) {
                // Compare the way fd_nodes' latin1_swedish_ci collation will
                // (case/accent-insensitive), not with a byte-exact ===: MySQL
                // would treat 'ACME' and 'Acme' as the SAME name, and a
                // byte-exact check would hand this account the other
                // account's folder.
                $nameTakenByOther = true;
            }
        }

        if ($mine !== null) {
            $id = (int)$mine['id'];
            try {
                if ($mine['deleted_at'] !== null) {
                    $db->prepare("UPDATE fd_nodes SET deleted_at = NULL, updated_at = NOW() WHERE id = ?")
                       ->execute([$id]);
                }
                // Keep the folder name in sync with the account name.
                if ($mine['name'] !== $wanted && !$nameTakenByOther) {
                    $db->prepare("UPDATE fd_nodes SET name = ?, updated_at = NOW() WHERE id = ?")
                       ->execute([$wanted, $id]);
                }
            } catch (Throwable $e) {
                error_log('FlowDriveSync::ensureAccountFolder rename: ' . $e->getMessage());
            }
            return $id;
        }

        $name = $nameTakenByOther ? ($wanted . ' (' . $accountId . ')') : $wanted;
        $id = FlowDriveRepo::ensureFolder($db, $driveId, $topId, $name, ['fw_account_id' => $accountId]);
        return self::claimOrDisambiguate($db, $driveId, $topId, $id, $accountId, $wanted);
    }

    /**
     * ensureFolder matches names with MySQL's collation, so the id it returns
     * can be ANOTHER account's folder even after the PHP-side clash check.
     * Verify ownership via meta: claim ownerless (legacy) folders, and force a
     * "(accountId)"-suffixed folder when the returned one belongs to someone
     * else. This is the invariant that keeps two accounts' documents from
     * merging into one folder.
     */
    private static function claimOrDisambiguate(PDO $db, int $driveId, int $topId, ?int $folderId, int $accountId, string $wanted): ?int
    {
        if ($folderId === null) {
            return null;
        }
        try {
            $stmt = $db->prepare("SELECT meta_json FROM fd_nodes WHERE id = ?");
            $stmt->execute([$folderId]);
            $meta = $stmt->fetchColumn();
            $meta = $meta ? json_decode((string)$meta, true) : null;
            $owner = is_array($meta) ? (int)($meta['fw_account_id'] ?? 0) : 0;

            if ($owner === $accountId) {
                return $folderId;
            }
            if ($owner === 0) {
                // Legacy/ownerless folder with a matching name — claim it.
                $db->prepare("UPDATE fd_nodes SET meta_json = ?, updated_at = NOW() WHERE id = ?")
                   ->execute([json_encode(['fw_account_id' => $accountId], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $folderId]);
                return $folderId;
            }

            // Collation-equal clash with a different account: use a suffixed
            // name, which cannot collide again (the id makes it unique).
            $suffixed = $wanted . ' (' . $accountId . ')';
            $id = FlowDriveRepo::ensureFolder($db, $driveId, $topId, $suffixed, ['fw_account_id' => $accountId]);
            if ($id !== null && $id !== $folderId) {
                return $id;
            }
            return null;
        } catch (Throwable $e) {
            error_log('FlowDriveSync::claimOrDisambiguate: ' . $e->getMessage());
            return $folderId;
        }
    }

    /**
     * Approximate fd_nodes' latin1_swedish_ci comparison in PHP: lowercase
     * and accent-fold, so names MySQL considers equal compare equal here.
     */
    private static function nameKey(string $name): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($ascii !== false) {
            $name = $ascii;
        }
        return function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name);
    }

    private static function loadAccount(PDO $db, int $companyId, int $accountId): ?array
    {
        try {
            $stmt = $db->prepare("SELECT id, type, name FROM crm_accounts WHERE id = ? AND company_id = ? LIMIT 1");
            $stmt->execute([$accountId, $companyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('FlowDriveSync::loadAccount: ' . $e->getMessage());
            return null;
        }
    }

    /** Sanitize a filename the same way folder names are (keeps the ext). */
    public static function sanitizeFilename(string $filename): string
    {
        $dot  = strrpos($filename, '.');
        $base = $dot === false ? $filename : substr($filename, 0, $dot);
        $ext  = $dot === false ? '' : substr($filename, $dot + 1);
        $base = self::sanitizeName($base) ?: 'file';
        return $base . ($ext !== '' ? '.' . $ext : '');
    }

    /**
     * Folder-safe display name: strip path separators and control chars, and
     * transliterate to latin1-representable characters — fd_nodes.name is a
     * latin1 column, so anything outside latin1 (emoji etc.) would make the
     * insert/compare fail with an illegal-mix-of-collations error.
     */
    public static function sanitizeName(string $name): string
    {
        $latin1 = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $name);
        if ($latin1 !== false) {
            $name = (string)@iconv('ISO-8859-1', 'UTF-8', $latin1);
        }
        $name = preg_replace('/[\x00-\x1f\x7f\/\\\\:*?"<>|]/u', '', $name) ?? '';
        $name = preg_replace('/\s+/', ' ', $name) ?? '';
        $name = trim($name, " .");
        return function_exists('mb_substr') ? mb_substr($name, 0, 200) : substr($name, 0, 200);
    }
}
