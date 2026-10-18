<?php
/**
 * DocumentPdfService — renders a quote / invoice / credit note to PDF the
 * moment it is created or changed, saves it under the module's storage tree
 * (storage/qi/{company_id}/{type}/{number}.pdf, served via /download.php),
 * and publishes a copy into FlowWork Drive under the customer/supplier's
 * folder (Customers/{Name}/Invoices/... etc — see FlowDriveSync).
 *
 * Every lifecycle endpoint (save, send, convert, duplicate, recurring,
 * payment) produces the same PDF: the document model (QiDocument) drawn with
 * the company's design (QiTheme) by DocPdf — the code the designer previews.
 *
 * generateAndFile() is safe to call from post-commit hooks: it never throws
 * and logs failures instead — document creation must not fail because the
 * PDF or drive copy could not be produced.
 */

require_once __DIR__ . '/../lib/QiTheme.php';
require_once __DIR__ . '/../lib/QiDocument.php';
require_once __DIR__ . '/../lib/DocPdf.php';
require_once __DIR__ . '/../../includes/flowdrive/FlowDriveSync.php';

class DocumentPdfService
{
    /** Last error surfaced by render()/fileToDrive() — read by the diagnostic. */
    public static $lastError = null;

    /**
     * Render the document, write it to storage/qi/... and (for invoices and
     * quotes) persist pdf_path on the row.
     *
     * @param string $type 'invoice' | 'quote' | 'credit_note'
     * @return array{bytes:string, filename:string, number:string, customer_id:int, rel_path:string, abs_path:string}|null
     */
    public static function render(PDO $db, int $companyId, string $type, int $docId): ?array
    {
        try {
            switch ($type) {
                case 'invoice':
                    return self::renderInvoice($db, $companyId, $docId);
                case 'quote':
                    return self::renderQuote($db, $companyId, $docId);
                case 'credit_note':
                    return self::renderCreditNote($db, $companyId, $docId);
                default:
                    error_log("DocumentPdfService::render: unknown type '$type'");
                    return null;
            }
        } catch (Throwable $e) {
            self::$lastError = "render($type #$docId): " . $e->getMessage()
                . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']';
            error_log("DocumentPdfService::" . self::$lastError);
            return null;
        }
    }

    /**
     * Render + store the PDF, then publish it to FlowWork Drive under the
     * document's customer/supplier. Never throws.
     */
    public static function generateAndFile(PDO $db, int $companyId, string $type, int $docId, ?int $userId = null): ?array
    {
        $r = self::render($db, $companyId, $type, $docId);
        if ($r !== null) {
            self::fileToDrive($db, $companyId, $type, $r, $userId);
        }
        return $r;
    }

    /** Publish an already-rendered document into FlowWork Drive. */
    public static function fileToDrive(PDO $db, int $companyId, string $type, array $r, ?int $userId = null): void
    {
        try {
            if (empty($r['customer_id']) || empty($r['bytes'])) {
                return;
            }
            FlowDriveSync::fileAccountDocument(
                $db,
                $companyId,
                (int)$r['customer_id'],
                self::driveCategory($type),
                $r['filename'],
                $r['bytes'],
                'application/pdf',
                $userId
            );
        } catch (Throwable $e) {
            self::$lastError = "fileToDrive($type): " . $e->getMessage()
                . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']';
            error_log("DocumentPdfService::" . self::$lastError);
        }
    }

    /**
     * Remove the document's PDF from FlowWork Drive (source was deleted).
     * Never throws. Call BEFORE the row is removed, or pass the values you
     * captured earlier.
     */
    public static function removeFromDrive(PDO $db, int $companyId, string $type, int $customerId, string $number): void
    {
        try {
            if ($customerId <= 0 || $number === '') {
                return;
            }
            FlowDriveSync::removeAccountDocument(
                $db,
                $companyId,
                $customerId,
                self::driveCategory($type),
                self::safeCode($number) . '.pdf'
            );
        } catch (Throwable $e) {
            error_log("DocumentPdfService::removeFromDrive($type): " . $e->getMessage());
        }
    }

    public static function driveCategory(string $type): string
    {
        switch ($type) {
            case 'quote':       return FlowDriveSync::CAT_QUOTES;
            case 'credit_note': return FlowDriveSync::CAT_CREDIT_NOTES;
            default:            return FlowDriveSync::CAT_INVOICES;
        }
    }

    public static function safeCode(string $number): string
    {
        return preg_replace('~[^A-Za-z0-9_-]~', '_', $number);
    }

    // ------------------------------------------------------------------
    // rendering — one model (QiDocument), the company's design (QiTheme),
    // the same renderer as the designer and the on-screen paper (DocPdf)
    // ------------------------------------------------------------------

    /** PDF bytes for a document as the company has designed it (no storage). */
    public static function bytes(PDO $db, int $companyId, string $type, int $docId, ?array &$model = null): ?string
    {
        $model = QiDocument::load($db, $companyId, $type, $docId);
        if ($model === null) {
            return null;
        }
        $st = $db->prepare("SELECT * FROM companies WHERE id = ?");
        $st->execute([$companyId]);
        $company = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $model['company']['raw'] = $company;
        $design = QiTheme::load($db, $companyId, $company);
        return DocPdf::render($model, $design);
    }

    private static function renderType(PDO $db, int $companyId, string $type, int $docId): ?array
    {
        $model = null;
        $bytes = self::bytes($db, $companyId, $type, $docId, $model);
        if ($bytes === null || $model === null) {
            return null;
        }
        $r = self::store($db, $companyId, $type, $model['number'], $bytes);
        $r['customer_id'] = (int)$model['customer']['id'];
        if ($type !== 'credit_note') {
            // updated_at = updated_at: publishing a PDF is not a business change;
            // preserving the timestamp keeps the daily drive sweep (which selects
            // on updated_at) from re-flagging documents it just published.
            $table = $type === 'quote' ? 'quotes' : 'invoices';
            $upd = $db->prepare("UPDATE `$table` SET pdf_path = ?, updated_at = updated_at WHERE id = ? AND company_id = ?");
            $upd->execute([$r['rel_path'], $docId, $companyId]);
        }
        return $r;
    }

    private static function renderInvoice(PDO $db, int $companyId, int $invoiceId): ?array
    {
        return self::renderType($db, $companyId, 'invoice', $invoiceId);
    }

    private static function renderQuote(PDO $db, int $companyId, int $quoteId): ?array
    {
        return self::renderType($db, $companyId, 'quote', $quoteId);
    }

    private static function renderCreditNote(PDO $db, int $companyId, int $creditNoteId): ?array
    {
        // credit_notes has no pdf_path column — file storage + drive copy only.
        return self::renderType($db, $companyId, 'credit_note', $creditNoteId);
    }

    /** Write the PDF under storage/qi/{companyId}/{type}/ and describe it. */
    private static function store(PDO $db, int $companyId, string $type, string $number, string $bytes): array
    {
        $safeCode = self::safeCode($number);
        $baseDir  = __DIR__ . '/../../storage/qi/' . $companyId . '/' . $type;
        if (!is_dir($baseDir)) {
            @mkdir($baseDir, 0775, true);
        }
        $absPath = $baseDir . '/' . $safeCode . '.pdf';
        $relPath = '/storage/qi/' . $companyId . '/' . $type . '/' . $safeCode . '.pdf';
        if (@file_put_contents($absPath, $bytes) === false) {
            error_log("DocumentPdfService::store: could not write $absPath");
        }

        return [
            'bytes'    => $bytes,
            'filename' => $safeCode . '.pdf',
            'number'   => $number,
            'rel_path' => $relPath,
            'abs_path' => $absPath,
        ];
    }
}
