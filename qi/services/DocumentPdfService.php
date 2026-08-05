<?php
/**
 * DocumentPdfService — renders a quote / invoice / credit note to PDF the
 * moment it is created or changed, saves it under the module's storage tree
 * (storage/qi/{company_id}/{type}/{number}.pdf, served via /download.php),
 * and publishes a copy into FlowWork Drive under the customer/supplier's
 * folder (Customers/{Name}/Invoices/... etc — see FlowDriveSync).
 *
 * The fetch + branding + writer recipe is the same one send_invoice.php /
 * send_quote.php used inline, extracted so every lifecycle endpoint (save,
 * send, convert, duplicate, recurring, payment) produces an identical PDF.
 *
 * generateAndFile() is safe to call from post-commit hooks: it never throws
 * and logs failures instead — document creation must not fail because the
 * PDF or drive copy could not be produced.
 */

require_once __DIR__ . '/../lib/Branding.php';
require_once __DIR__ . '/../lib/LineHeadings.php';
require_once __DIR__ . '/../../includes/pdf/qi_styled_pdf.php';
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
    // per-type renderers
    // ------------------------------------------------------------------

    private static function renderInvoice(PDO $db, int $companyId, int $invoiceId): ?array
    {
        $stmt = $db->prepare(
            "SELECT i.*,
                    c.name AS company_name, c.logo_url, c.vat_number, c.tax_number, c.reg_number,
                    c.website, c.phone AS company_phone, c.email AS company_email,
                    c.address_line1 AS company_address1, c.address_line2 AS company_address2,
                    c.city AS company_city, c.region AS company_region, c.postal AS company_postal,
                    c.bank_name, c.bank_account_number, c.bank_branch_code,
                    c.primary_color, c.secondary_color, c.qi_heading_color, c.qi_text_color,
                    c.qi_table_header_text, c.qi_bg_color, c.qi_font_family, c.qi_logo_size,
                    c.qi_show_company_address, c.qi_show_company_phone, c.qi_show_company_email,
                    c.qi_show_company_website, c.qi_show_vat_number, c.qi_show_tax_number,
                    c.qi_show_reg_number, c.qi_show_payment_details, c.qi_quote_title, c.qi_invoice_title,
                    c.invoice_footer_text, c.quote_footer_text,
                    ca.name AS customer_name, ca.email AS customer_email, ca.phone AS customer_phone,
                    ca.vat_no AS customer_vat, ca.reg_no AS customer_reg,
                    addr.line1 AS customer_address1, addr.line2 AS customer_address2,
                    addr.city AS customer_city, addr.region AS customer_region, addr.postal_code AS customer_postal,
                    p.name AS project_name
             FROM invoices i
             LEFT JOIN crm_accounts ca ON i.customer_id = ca.id
             LEFT JOIN companies c ON i.company_id = c.id
             LEFT JOIN crm_addresses addr ON addr.account_id = ca.id AND addr.id = (SELECT a2.id FROM crm_addresses a2 WHERE a2.account_id = ca.id ORDER BY FIELD(a2.type, 'billing', 'head_office', 'shipping', 'site') LIMIT 1)
             LEFT JOIN projects p ON i.project_id = p.project_id
             WHERE i.id = ? AND i.company_id = ?"
        );
        $stmt->execute([$invoiceId, $companyId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            return null;
        }

        $stmtLines = $db->prepare("SELECT item_description, quantity, unit_price, discount, line_total, sort_order FROM invoice_lines WHERE invoice_id = ? ORDER BY sort_order");
        $stmtLines->execute([$invoiceId]);
        $lineRows = $stmtLines->fetchAll(PDO::FETCH_ASSOC);
        // Interleave section headings (board-group style); heading rows carry
        // _kind = 'heading' and no amounts, and the writer renders them as
        // full-width section bands with a per-section total (excl. VAT)
        // after each section's items.
        $lineRows = LineHeadings::withSectionTotals(
            LineHeadings::merge($lineRows, LineHeadings::fetch($db, LineHeadings::TYPE_INVOICE, $invoiceId))
        );

        $brand = Branding::resolve($invoice, 'invoice');
        $invoice['_doc_type']    = $brand['title'];
        $invoice['_doc_title']   = 'Invoice #: ' . $invoice['invoice_number'];
        $invoice['_footer_text'] = $brand['footer'];
        $invoice['_dates'] = [
            'Issue Date' => date('d M Y', strtotime($invoice['issue_date'])),
            'Due Date'   => date('d M Y', strtotime($invoice['due_date'])),
            'Status'     => ucfirst($invoice['status']),
        ];

        if (!empty($invoice['has_milestones'])) {
            $stmtMs = $db->prepare("SELECT * FROM payment_milestones WHERE entity_type = 'invoice' AND entity_id = ? AND company_id = ? ORDER BY sort_order");
            $stmtMs->execute([$invoiceId, $companyId]);
            $invoice['_milestones'] = $stmtMs->fetchAll(PDO::FETCH_ASSOC);
        }
        $stmtPay = $db->prepare(
            "SELECT p.payment_date, p.method, p.reference, pa.amount
             FROM payment_allocations pa
             JOIN payments p ON pa.payment_id = p.id
             WHERE pa.invoice_id = ? AND p.company_id = ?
             ORDER BY p.payment_date ASC, p.id ASC"
        );
        $stmtPay->execute([$invoiceId, $companyId]);
        $invoice['_payments'] = $stmtPay->fetchAll(PDO::FETCH_ASSOC);

        $pdf   = new QiStyledPdfWriter($invoice, $lineRows);
        $bytes = $pdf->render();

        $r = self::store($db, $companyId, 'invoice', $invoice['invoice_number'], $bytes);
        $r['customer_id'] = (int)$invoice['customer_id'];

        // updated_at = updated_at: publishing a PDF is not a business change;
        // preserving the timestamp keeps the daily drive sweep (which selects
        // on updated_at) from re-flagging documents it just published.
        $upd = $db->prepare("UPDATE invoices SET pdf_path = ?, updated_at = updated_at WHERE id = ? AND company_id = ?");
        $upd->execute([$r['rel_path'], $invoiceId, $companyId]);

        return $r;
    }

    private static function renderQuote(PDO $db, int $companyId, int $quoteId): ?array
    {
        $stmt = $db->prepare(
            "SELECT q.*,
                    c.name AS company_name, c.logo_url, c.vat_number, c.tax_number, c.reg_number,
                    c.website, c.phone AS company_phone, c.email AS company_email,
                    c.address_line1 AS company_address1, c.address_line2 AS company_address2,
                    c.city AS company_city, c.region AS company_region, c.postal AS company_postal,
                    c.bank_name, c.bank_account_number, c.bank_branch_code,
                    c.primary_color, c.secondary_color, c.qi_heading_color, c.qi_text_color,
                    c.qi_table_header_text, c.qi_bg_color, c.qi_font_family, c.qi_logo_size,
                    c.qi_show_company_address, c.qi_show_company_phone, c.qi_show_company_email,
                    c.qi_show_company_website, c.qi_show_vat_number, c.qi_show_tax_number,
                    c.qi_show_reg_number, c.qi_show_payment_details, c.qi_quote_title, c.qi_invoice_title,
                    c.invoice_footer_text, c.quote_footer_text,
                    ca.name AS customer_name, ca.email AS customer_email, ca.phone AS customer_phone,
                    ca.vat_no AS customer_vat, ca.reg_no AS customer_reg,
                    addr.line1 AS customer_address1, addr.line2 AS customer_address2,
                    addr.city AS customer_city, addr.region AS customer_region, addr.postal_code AS customer_postal,
                    p.name AS project_name
             FROM quotes q
             LEFT JOIN crm_accounts ca ON q.customer_id = ca.id
             LEFT JOIN companies c ON q.company_id = c.id
             LEFT JOIN crm_addresses addr ON addr.account_id = ca.id AND addr.id = (SELECT a2.id FROM crm_addresses a2 WHERE a2.account_id = ca.id ORDER BY FIELD(a2.type, 'billing', 'head_office', 'shipping', 'site') LIMIT 1)
             LEFT JOIN projects p ON q.project_id = p.project_id
             WHERE q.id = ? AND q.company_id = ?"
        );
        $stmt->execute([$quoteId, $companyId]);
        $quote = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$quote) {
            return null;
        }

        $stmtLines = $db->prepare("SELECT item_description, quantity, unit_price, discount, line_total, sort_order FROM quote_lines WHERE quote_id = ? ORDER BY sort_order");
        $stmtLines->execute([$quoteId]);
        $lineRows = $stmtLines->fetchAll(PDO::FETCH_ASSOC);
        // Interleave section headings (board-group style) — see renderInvoice.
        $lineRows = LineHeadings::withSectionTotals(
            LineHeadings::merge($lineRows, LineHeadings::fetch($db, LineHeadings::TYPE_QUOTE, $quoteId))
        );

        $brand = Branding::resolve($quote, 'quote');
        $quote['_doc_type']    = $brand['title'];
        $quote['_doc_title']   = 'Quote #: ' . $quote['quote_number'];
        $quote['_footer_text'] = $brand['footer'];
        $quote['_dates'] = [
            'Issue Date' => date('d M Y', strtotime($quote['issue_date'])),
            'Expires'    => date('d M Y', strtotime($quote['expiry_date'])),
            'Status'     => ucfirst($quote['status']),
        ];

        $pdf   = new QiStyledPdfWriter($quote, $lineRows);
        $bytes = $pdf->render();

        $r = self::store($db, $companyId, 'quote', $quote['quote_number'], $bytes);
        $r['customer_id'] = (int)$quote['customer_id'];

        // updated_at preserved — see the matching comment on the invoice update.
        $upd = $db->prepare("UPDATE quotes SET pdf_path = ?, updated_at = updated_at WHERE id = ? AND company_id = ?");
        $upd->execute([$r['rel_path'], $quoteId, $companyId]);

        return $r;
    }

    private static function renderCreditNote(PDO $db, int $companyId, int $creditNoteId): ?array
    {
        $stmt = $db->prepare(
            "SELECT cn.*,
                    c.name AS company_name, c.logo_url, c.vat_number, c.tax_number, c.reg_number,
                    c.website, c.phone AS company_phone, c.email AS company_email,
                    c.address_line1 AS company_address1, c.address_line2 AS company_address2,
                    c.city AS company_city, c.region AS company_region, c.postal AS company_postal,
                    c.bank_name, c.bank_account_number, c.bank_branch_code,
                    c.primary_color, c.secondary_color, c.qi_heading_color, c.qi_text_color,
                    c.qi_table_header_text, c.qi_bg_color, c.qi_font_family, c.qi_logo_size,
                    c.qi_show_company_address, c.qi_show_company_phone, c.qi_show_company_email,
                    c.qi_show_company_website, c.qi_show_vat_number, c.qi_show_tax_number,
                    c.qi_show_reg_number, c.qi_show_payment_details, c.qi_quote_title, c.qi_invoice_title,
                    c.invoice_footer_text, c.quote_footer_text,
                    ca.name AS customer_name, ca.email AS customer_email, ca.phone AS customer_phone,
                    ca.vat_no AS customer_vat, ca.reg_no AS customer_reg,
                    addr.line1 AS customer_address1, addr.line2 AS customer_address2,
                    addr.city AS customer_city, addr.region AS customer_region, addr.postal_code AS customer_postal,
                    i.invoice_number AS linked_invoice_number
             FROM credit_notes cn
             LEFT JOIN crm_accounts ca ON cn.customer_id = ca.id
             LEFT JOIN companies c ON cn.company_id = c.id
             LEFT JOIN crm_addresses addr ON addr.account_id = ca.id AND addr.id = (SELECT a2.id FROM crm_addresses a2 WHERE a2.account_id = ca.id ORDER BY FIELD(a2.type, 'billing', 'head_office', 'shipping', 'site') LIMIT 1)
             LEFT JOIN invoices i ON cn.invoice_id = i.id
             WHERE cn.id = ? AND cn.company_id = ?"
        );
        $stmt->execute([$creditNoteId, $companyId]);
        $cn = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cn) {
            return null;
        }

        $stmtLines = $db->prepare("SELECT item_description, quantity, unit_price, line_total FROM credit_note_lines WHERE credit_note_id = ? ORDER BY sort_order");
        $stmtLines->execute([$creditNoteId]);
        $lineRows = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

        $cn['project_name']  = null;
        $brand = Branding::resolve($cn, 'credit_note');
        $cn['_doc_type']    = $brand['title'] ?: 'CREDIT NOTE';
        $cn['_doc_title']   = 'Credit Note #: ' . $cn['credit_note_number'];
        $cn['_footer_text'] = $brand['footer'];
        $cn['_dates'] = [
            'Issue Date' => date('d M Y', strtotime($cn['issue_date'])),
            'Status'     => ucfirst((string)($cn['status'] ?? '')),
        ];
        if (!empty($cn['linked_invoice_number'])) {
            $cn['_dates']['Invoice'] = $cn['linked_invoice_number'];
        }

        $pdf   = new QiStyledPdfWriter($cn, $lineRows);
        $bytes = $pdf->render();

        // credit_notes has no pdf_path column — file storage + drive copy only.
        $r = self::store($db, $companyId, 'credit_note', $cn['credit_note_number'], $bytes);
        $r['customer_id'] = (int)$cn['customer_id'];

        return $r;
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
