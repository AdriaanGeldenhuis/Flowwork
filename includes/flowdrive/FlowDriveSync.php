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
            $folderId = self::ensureCategoryFolder($db, $companyId, $accountId, $category);
            if ($folderId === null) {
                return null;
            }
            [$driveId] = self::driveFor($db, $companyId);
            return FlowDriveRepo::putFile($db, $driveId, $folderId, self::sanitizeFilename($filename), $bytes, $mime, $userId);
        } catch (Throwable $e) {
            error_log('FlowDriveSync::fileAccountDocument: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Publish an uploaded compliance document under the account's Documents
     * folder, named "{Type name} - {reference|id}.{ext}" so re-uploads of the
     * same type+reference replace the drive copy. Never throws.
     */
    public static function fileComplianceDoc(
        PDO $db,
        int $companyId,
        int $accountId,
        int $typeId,
        string $reference,
        string $absPath,
        ?int $userId = null
    ): ?int {
        try {
            if (!is_file($absPath)) {
                return null;
            }
            $bytes = @file_get_contents($absPath);
            if ($bytes === false || $bytes === '') {
                return null;
            }

            $typeName = '';
            try {
                $stmt = $db->prepare("SELECT name FROM crm_compliance_types WHERE id = ? AND company_id = ?");
                $stmt->execute([$typeId, $companyId]);
                $typeName = (string)($stmt->fetchColumn() ?: '');
            } catch (Throwable $e) {
                // fall back to the raw filename below
            }

            $ext   = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
            $label = self::sanitizeName($typeName) ?: 'Compliance Document';
            $ref   = self::sanitizeName($reference);
            $filename = $label . ($ref !== '' ? ' - ' . $ref : '') . ($ext !== '' ? '.' . $ext : '');

            $mimeMap = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
            $mime = $mimeMap[$ext] ?? 'application/octet-stream';
            if (function_exists('mime_content_type')) {
                $detected = @mime_content_type($absPath);
                if (is_string($detected) && $detected !== '') {
                    $mime = $detected;
                }
            }

            return self::fileAccountDocument($db, $companyId, $accountId, self::CAT_DOCUMENTS, $filename, $bytes, $mime, $userId);
        } catch (Throwable $e) {
            error_log('FlowDriveSync::fileComplianceDoc: ' . $e->getMessage());
            return null;
        }
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
                $mine = $sib;
            } elseif ($sib['name'] === $wanted && $sib['deleted_at'] === null) {
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
        return FlowDriveRepo::ensureFolder($db, $driveId, $topId, $name, ['fw_account_id' => $accountId]);
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
        return mb_substr($name, 0, 200);
    }
}
