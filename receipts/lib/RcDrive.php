<?php
// /receipts/lib/RcDrive.php — receipts in FlowWork Drive. The slip behind a
// bill in the books is filed with the supplier's other papers:
//
//   Suppliers/{Supplier}/Receipts/2026-10-14 BW-204551.pdf
//
// next to what Flowwork already publishes there (includes/flowdrive/). A
// receipt is filed when its bill is posted (RcBills::post); older ones, and
// any a drive hiccup missed, are filed by FlowDriveBackfill on page loads.
// receipt_file.drive_node_id remembers the drive file — 0 when the slip
// cannot be filed (gone from the server, or larger than the drive takes).
//
// Only posted bills' slips go: drafts and slips set aside stay in Receipts,
// as the drive is open to everyone in the company. An admin can stop filing
// (Settings → Sending in); what is already in the drive stays there.

require_once __DIR__ . '/RcKit.php';
require_once __DIR__ . '/RcSchema.php';
require_once __DIR__ . '/RcFiles.php';
require_once dirname(__DIR__, 2) . '/includes/flowdrive/FlowDriveSync.php';

final class RcDrive
{
    /** Whether this company's posted receipts are filed in the drive. */
    public static function enabled(PDO $db, int $companyId): bool
    {
        RcSchema::ensure($db);
        return !empty(RcKit::settings($db, $companyId)['publish_to_drive']) && FlowDriveRepo::available($db);
    }

    /** Posted receipts not in the drive yet, after $afterId, oldest first. @return int[] */
    public static function pending(PDO $db, int $companyId, int $afterId = 0, int $limit = 100): array
    {
        try {
            if (!self::enabled($db, $companyId)) return [];
            $st = $db->prepare("SELECT rf.file_id FROM receipt_file rf JOIN ap_bills b ON b.id = rf.bill_id AND b.company_id = rf.company_id
                                 WHERE rf.company_id = ? AND rf.file_id > ? AND rf.drive_node_id IS NULL AND b.status IN ('posted', 'paid')
                              ORDER BY rf.file_id LIMIT " . max(1, min(1000, $limit)));
            $st->execute([$companyId, $afterId]);
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            error_log('[receipts] drive pending: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * File one posted receipt in the drive (again, if it is there already —
     * the same bytes make no new version). Never throws.
     * @return int|null the drive file's id, or null when it was not filed
     */
    public static function publish(PDO $db, int $companyId, int $fileId, ?int $userId = null): ?int
    {
        try {
            if (!self::enabled($db, $companyId)) return null;
            $st = $db->prepare("SELECT rf.file_id, rf.path, rf.mime, b.supplier_id, b.vendor_invoice_number, b.issue_date
                                  FROM receipt_file rf JOIN ap_bills b ON b.id = rf.bill_id AND b.company_id = rf.company_id
                                 WHERE rf.file_id = ? AND rf.company_id = ? AND b.status IN ('posted', 'paid')");
            $st->execute([$fileId, $companyId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            $path = RcFiles::path($companyId, (string)$r['path']);
            $size = $path ? (int)@filesize($path) : 0;
            if ($size <= 0 || $size > FlowDriveRepo::MAX_BLOB_BYTES) {
                // Gone, or too large for the drive: say so once instead of trying on every page load.
                error_log('[receipts] drive: receipt ' . $fileId . ' not filed (' . ($path ? $size . ' bytes' : 'file missing') . ')');
                self::mark($db, $companyId, $fileId, 0);
                return null;
            }
            $bytes = @file_get_contents($path);
            if ($bytes === false || $bytes === '') return null;
            $node = FlowDriveSync::fileReceipt($db, $companyId, (int)$r['supplier_id'], $fileId, self::filename($r), $bytes, (string)$r['mime'], $userId);
            if ($node !== null) self::mark($db, $companyId, $fileId, $node);
            return $node;
        } catch (Throwable $e) {
            error_log('[receipts] drive publish ' . $fileId . ': ' . $e->getMessage());
            return null;
        }
    }

    /** How many receipts are in the drive. */
    public static function filed(PDO $db, int $companyId): int
    {
        try {
            $st = $db->prepare("SELECT COUNT(*) FROM receipt_file WHERE company_id = ? AND drive_node_id > 0");
            $st->execute([$companyId]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** "2026-10-14 BW-204551.pdf" — the bill's date and the supplier's number. */
    private static function filename(array $r): string
    {
        $number = FlowDriveSync::sanitizeName((string)($r['vendor_invoice_number'] ?? ''));
        $date = RcKit::ymd((string)($r['issue_date'] ?? '')) ?? '';
        $base = trim($date . ' ' . ($number !== '' ? $number : 'Receipt ' . (int)$r['file_id']));
        return $base . '.' . (RcFiles::TYPES[(string)$r['mime']] ?? 'pdf');
    }

    private static function mark(PDO $db, int $companyId, int $fileId, int $nodeId): void
    {
        $db->prepare("UPDATE receipt_file SET drive_node_id = ? WHERE file_id = ? AND company_id = ?")->execute([$nodeId, $fileId, $companyId]);
    }
}
