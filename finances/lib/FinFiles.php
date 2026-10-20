<?php
// finances/lib/FinFiles.php
//
// Supporting documents on journals, supplier bills, supplier credits, fixed
// assets and bank lines (SARS expects the source document behind every
// entry). Uploads are checked by their content (finfo), not their name:
// PDF, images, and Office / CSV files up to 10 MB. Each file gets a random
// name under storage/finance/<company>/<yyyy>/ (deny-all .htaccess; never
// served directly) and is only handed out by api/files.php after the
// company check. Once the document it supports is in the books (a posted
// journal, a posted or paid bill) a file can be added but no longer removed.

require_once __DIR__ . '/Fin.php';

final class FinFiles
{
    public const ENTITIES = ['journal', 'bill', 'vendor_credit', 'asset', 'bank_txn'];
    public const MAX_BYTES = 10 * 1024 * 1024;
    /** mime => extension */
    public const TYPES = [
        'application/pdf' => 'pdf',
        'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/heic' => 'heic',
        'text/csv' => 'csv', 'text/plain' => 'txt',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls', 'application/msword' => 'doc',
    ];

    public static function list(PDO $db, int $companyId, string $entity, int $entityId): array
    {
        try {
            $st = $db->prepare("SELECT id, original_name, mime, size_bytes, uploaded_by, created_at FROM fin_attachments
                                 WHERE company_id = ? AND entity = ? AND entity_id = ? AND deleted_at IS NULL ORDER BY id");
            $st->execute([$companyId, $entity, $entityId]);
        } catch (Throwable $e) {
            return [];
        }
        return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['original_name'], 'mime' => (string)$r['mime'], 'size' => (int)$r['size_bytes'],
                                    'by' => Fin::personName($db, $companyId, $r['uploaded_by']), 'at' => $r['created_at']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Does the document exist in this company, and is it already in the books (files then stay)? */
    public static function entityState(PDO $db, int $companyId, string $entity, int $entityId): array
    {
        $map = [
            'journal' => ["SELECT status FROM journal_entries WHERE id = ? AND company_id = ?", fn($s) => $s === 'posted'],
            'bill' => ["SELECT status FROM ap_bills WHERE id = ? AND company_id = ?", fn($s) => in_array($s, ['posted', 'paid'], true)],
            'vendor_credit' => ["SELECT IF(journal_id IS NULL, status, 'posted') FROM vendor_credits WHERE id = ? AND company_id = ?", fn($s) => $s === 'posted' || $s === 'applied'],
            'asset' => ["SELECT status FROM gl_fixed_assets WHERE asset_id = ? AND company_id = ?", fn($s) => false],
            'bank_txn' => ["SELECT IF(matched = 1, 'matched', 'open') FROM gl_bank_transactions WHERE bank_tx_id = ? AND company_id = ?", fn($s) => $s === 'matched'],
        ];
        if (!isset($map[$entity])) throw new FinUserError('Files cannot be attached to that.');
        $st = $db->prepare($map[$entity][0]);
        $st->execute([$entityId, $companyId]);
        $s = $st->fetchColumn();
        if ($s === false) throw new FinUserError('That document was not found.');
        return ['final' => ($map[$entity][1])((string)$s)];
    }

    /** Store an uploaded file ($_FILES entry). @return array the stored file row */
    public static function store(PDO $db, int $companyId, int $userId, string $entity, int $entityId, array $file): array
    {
        if (!in_array($entity, self::ENTITIES, true)) throw new FinUserError('Files cannot be attached to that.');
        self::entityState($db, $companyId, $entity, $entityId);
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new FinUserError(($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE
                ? 'That file is too large (10 MB at most).' : 'The file did not arrive. Please try again.');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || (!is_uploaded_file($tmp) && PHP_SAPI !== 'cli')) throw new FinUserError('The file did not arrive. Please try again.');
        $size = (int)filesize($tmp);
        if ($size <= 0) throw new FinUserError('That file is empty.');
        if ($size > self::MAX_BYTES) throw new FinUserError('That file is too large (10 MB at most).');
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $ext = self::TYPES[$mime] ?? null;
        $orig = self::cleanName((string)($file['name'] ?? 'document'));
        // Office files are zip containers; finfo sometimes reports the container.
        if (!$ext && in_array($mime, ['application/zip', 'application/octet-stream'], true)) {
            $e = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (in_array($e, ['xlsx', 'docx'], true) && self::isOfficeZip($tmp, $e)) { $ext = $e; $mime = array_search($e, self::TYPES, true); }
        }
        if (!$ext) throw new FinUserError('That kind of file is not allowed. Upload a PDF, a photo, or an Excel, Word or CSV file.');
        $dir = FinSchema::storageDir();
        if ($dir === null) throw new FinUserError('Files cannot be stored right now. Please tell your administrator.');
        $sub = $dir . '/' . $companyId . '/' . date('Y');
        if (!is_dir($sub) && !@mkdir($sub, 0750, true) && !is_dir($sub)) throw new FinUserError('Files cannot be stored right now. Please tell your administrator.');
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $sub . '/' . $name;
        $moved = PHP_SAPI === 'cli' ? @copy($tmp, $dest) : @move_uploaded_file($tmp, $dest);
        if (!$moved) throw new FinUserError('The file could not be saved. Please try again.');
        @chmod($dest, 0640);
        $sha = hash_file('sha256', $dest);
        try {
            $db->prepare("INSERT INTO fin_attachments (company_id, entity, entity_id, stored_name, original_name, mime, size_bytes, sha256, uploaded_by, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
               ->execute([$companyId, $entity, $entityId, date('Y') . '/' . $name, $orig, $mime, $size, $sha, $userId]);
        } catch (Throwable $e) {
            @unlink($dest);
            throw $e;
        }
        $id = (int)$db->lastInsertId();
        Fin::audit($db, $companyId, $userId, $entity . '_attachment_added', $entity === 'journal' ? 'journal' : $entity, $entityId, ['file_id' => $id, 'name' => $orig, 'sha256' => $sha]);
        return ['id' => $id, 'name' => $orig, 'mime' => $mime, 'size' => $size];
    }

    /**
     * Attach a copy of a file already on the server (a slip from Receipts) to a
     * document. The same content is attached once. @return int|null the attachment id
     */
    public static function attachCopy(PDO $db, int $companyId, int $userId, string $entity, int $entityId, string $path, string $name): ?int
    {
        if (!in_array($entity, self::ENTITIES, true) || !is_file($path)) return null;
        self::entityState($db, $companyId, $entity, $entityId);
        $size = (int)filesize($path);
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($path);
        $ext = self::TYPES[$mime] ?? null;
        if (!$ext || $size <= 0 || $size > 25 * 1024 * 1024) return null; // a slip from Receipts (checked there; up to 25 MB)
        $sha = hash_file('sha256', $path);
        $st = $db->prepare("SELECT id FROM fin_attachments WHERE company_id = ? AND entity = ? AND entity_id = ? AND sha256 = ? AND deleted_at IS NULL LIMIT 1");
        $st->execute([$companyId, $entity, $entityId, $sha]);
        if ($id = (int)$st->fetchColumn()) return $id;
        $dir = FinSchema::storageDir();
        if ($dir === null) return null;
        $sub = $dir . '/' . $companyId . '/' . date('Y');
        if (!is_dir($sub) && !@mkdir($sub, 0750, true) && !is_dir($sub)) return null;
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!@copy($path, $sub . '/' . $stored)) return null;
        @chmod($sub . '/' . $stored, 0640);
        $orig = self::cleanName($name);
        try {
            $db->prepare("INSERT INTO fin_attachments (company_id, entity, entity_id, stored_name, original_name, mime, size_bytes, sha256, uploaded_by, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
               ->execute([$companyId, $entity, $entityId, date('Y') . '/' . $stored, $orig, $mime, $size, $sha, $userId]);
        } catch (Throwable $e) {
            @unlink($sub . '/' . $stored);
            throw $e;
        }
        $id = (int)$db->lastInsertId();
        Fin::audit($db, $companyId, $userId, $entity . '_attachment_added', $entity, $entityId, ['file_id' => $id, 'name' => $orig, 'sha256' => $sha, 'from' => 'receipts']);
        return $id;
    }

    /** @return array{path:string, name:string, mime:string} */
    public static function open(PDO $db, int $companyId, int $id): array
    {
        $st = $db->prepare("SELECT stored_name, original_name, mime FROM fin_attachments WHERE id = ? AND company_id = ? AND deleted_at IS NULL");
        $st->execute([$id, $companyId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || !preg_match('#^\d{4}/[a-f0-9]{32}\.[a-z]{3,4}$#', $r['stored_name'])) throw new FinUserError('That file was not found.');
        $path = FinSchema::storageDir() . '/' . $companyId . '/' . $r['stored_name'];
        if (!is_file($path)) throw new FinUserError('That file was not found.');
        return ['path' => $path, 'name' => (string)$r['original_name'], 'mime' => (string)$r['mime']];
    }

    public static function remove(PDO $db, int $companyId, int $userId, int $id): void
    {
        $st = $db->prepare("SELECT entity, entity_id, original_name FROM fin_attachments WHERE id = ? AND company_id = ? AND deleted_at IS NULL");
        $st->execute([$id, $companyId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new FinUserError('That file was not found.');
        if (self::entityState($db, $companyId, $r['entity'], (int)$r['entity_id'])['final']) {
            throw new FinUserError('This document is in the books, so its supporting files are kept. Add a corrected file instead.', 409);
        }
        $db->prepare("UPDATE fin_attachments SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND company_id = ?")->execute([$userId, $id, $companyId]);
        Fin::audit($db, $companyId, $userId, $r['entity'] . '_attachment_removed', $r['entity'], (int)$r['entity_id'], ['file_id' => $id, 'name' => $r['original_name']]);
    }

    /** When a draft is deleted, its files go with it (soft delete). */
    public static function detachAll(PDO $db, int $companyId, int $userId, string $entity, int $entityId): void
    {
        try {
            $db->prepare("UPDATE fin_attachments SET deleted_at = NOW(), deleted_by = ? WHERE company_id = ? AND entity = ? AND entity_id = ? AND deleted_at IS NULL")
               ->execute([$userId, $companyId, $entity, $entityId]);
        } catch (Throwable $e) {
            // no attachments table yet
        }
    }

    public static function counts(PDO $db, int $companyId, string $entity, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return [];
        try {
            $st = $db->prepare("SELECT entity_id, COUNT(*) FROM fin_attachments WHERE company_id = ? AND entity = ? AND deleted_at IS NULL
                                   AND entity_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") GROUP BY entity_id");
            $st->execute(array_merge([$companyId, $entity], $ids));
            return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function cleanName(string $n): string
    {
        $n = basename(str_replace('\\', '/', $n));
        $n = preg_replace('/[\x00-\x1f\x7f"<>:|?*]+/u', '', $n);
        $n = trim($n) ?: 'document';
        return mb_substr($n, 0, 200);
    }

    private static function isOfficeZip(string $path, string $ext): bool
    {
        if (!class_exists('ZipArchive')) return false;
        $z = new ZipArchive();
        if ($z->open($path) !== true) return false;
        $ok = $z->locateName('[Content_Types].xml') !== false && $z->locateName($ext === 'xlsx' ? 'xl/workbook.xml' : 'word/document.xml') !== false;
        $z->close();
        return $ok;
    }
}
