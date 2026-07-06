<?php
// finances/lib/TaxInvoiceValidator.php
//
// Validates that an invoice meets SARS Section 20 tax invoice requirements.
// SARS requires specific fields on all tax invoices:
// - The words "Tax Invoice" or "Credit Note"
// - Seller's name, address, and VAT registration number
// - Buyer's name and (for invoices > R5,000) VAT registration number + address
// - Serial number (invoice number)
// - Date of issue
// - Description of goods/services per line
// - VAT rate and amount per line
// - Total including VAT
//
// The full rule set only applies when the company IS VAT-registered
// (companies.vat_number non-empty). A non-registered company cannot issue a
// tax invoice at all, so the validator returns a single info-level note and
// nothing else. Invoices with a total of R5,000 or less qualify as ABRIDGED
// tax invoices (s20(5)): the recipient's details are not required.

class TaxInvoiceValidator
{
    private $db;
    private $companyId;

    /** @var array<string,bool> column-existence cache, key "table.column" */
    private static $columnCache = [];

    public function __construct(PDO $db, int $companyId)
    {
        $this->db = $db;
        $this->companyId = $companyId;
    }

    /**
     * Validate an invoice for SARS Section 20 compliance.
     * Returns an array of issues. Empty array = compliant.
     *
     * @param int $invoiceId
     * @return array Array of ['field' => string, 'message' => string, 'severity' => 'error'|'warning'|'info']
     */
    public function validate(int $invoiceId): array
    {
        $issues = [];

        // Fetch invoice with customer from crm_accounts (+ the customer's best
        // address from crm_addresses — billing first, same preference order as
        // the document renderers).
        $stmt = $this->db->prepare(
            "SELECT i.*, c.name AS customer_name, c.vat_no AS customer_vat,
                    c.email AS customer_email,
                    addr.line1 AS customer_address1, addr.city AS customer_city
             FROM invoices i
             LEFT JOIN crm_accounts c ON c.id = i.customer_id
             LEFT JOIN crm_addresses addr ON addr.account_id = c.id
                  AND addr.id = (SELECT a2.id FROM crm_addresses a2
                                  WHERE a2.account_id = c.id
                                  ORDER BY FIELD(a2.type, 'billing', 'head_office', 'shipping', 'site')
                                  LIMIT 1)
             WHERE i.id = ? AND i.company_id = ?"
        );
        $stmt->execute([$invoiceId, $this->companyId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            return [['field' => 'invoice', 'message' => 'Invoice not found', 'severity' => 'error']];
        }

        // Fetch company details (supplier side of the tax invoice)
        $stmt = $this->db->prepare(
            "SELECT name, vat_number, reg_number, address_line1, city FROM companies WHERE id = ?"
        );
        $stmt->execute([$this->companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Not VAT-registered: the s20 tax-invoice rules do not apply. Return a
        // single informational note so callers can surface why nothing was
        // checked; isCompliant() stays true (no errors).
        $vatNumber = trim((string)($company['vat_number'] ?? ''));
        if ($vatNumber === '') {
            return [[
                'field' => 'company_vat_number',
                'message' => 'Company is not VAT-registered — SARS tax invoice rules not applied',
                'severity' => 'info',
            ]];
        }

        // 1. Seller VAT number format: SA VAT numbers are 10 digits starting
        //    with 4 (s20(4)(a) requires the supplier's VAT registration number).
        if (!preg_match('/^4\d{9}$/', $vatNumber)) {
            $issues[] = [
                'field' => 'company_vat_number',
                'message' => 'Company VAT number "' . $vatNumber . '" is not a valid South African VAT number (10 digits starting with 4). Fix it in Admin > Company Profile.',
                'severity' => 'error'
            ];
        }

        // 2. Seller physical address (s20(4)(b)): address line 1 and city must
        //    be captured on the company profile.
        if (trim((string)($company['address_line1'] ?? '')) === '' || trim((string)($company['city'] ?? '')) === '') {
            $issues[] = [
                'field' => 'company_address',
                'message' => 'Company address (street + city) is required on a tax invoice (SARS Section 20(4)(b)). Set it in Admin > Company Profile.',
                'severity' => 'error'
            ];
        }

        // 3. "Tax Invoice" wording (s20(4)(a)): a custom document title that
        //    drops the words "tax invoice" is non-compliant. The title column
        //    lives in companies.qi_invoice_title on newer schemas — check it
        //    exists before validating (Branding.php force-fixes the rendered
        //    title, but the stored setting should still be corrected).
        $customTitle = $this->fetchInvoiceTitle();
        if ($customTitle !== null && trim($customTitle) !== ''
            && stripos($customTitle, 'tax invoice') === false) {
            $issues[] = [
                'field' => 'qi_invoice_title',
                'message' => 'Custom invoice title "' . trim($customTitle) . '" must contain the words "Tax Invoice" (SARS Section 20(4)(a)). Update it in Q&I Settings > Branding.',
                'severity' => 'error'
            ];
        }

        // 4. Invoice number (serial number)
        if (empty($invoice['invoice_number'])) {
            $issues[] = [
                'field' => 'invoice_number',
                'message' => 'Invoice number is missing (SARS Section 20(4)(c))',
                'severity' => 'error'
            ];
        }

        // 5. Issue date
        if (empty($invoice['issue_date'])) {
            $issues[] = [
                'field' => 'issue_date',
                'message' => 'Issue date is missing (SARS Section 20(4)(d))',
                'severity' => 'error'
            ];
        }

        // 6. Customer name
        if (empty($invoice['customer_name'])) {
            $issues[] = [
                'field' => 'customer_name',
                'message' => 'Customer name is missing (SARS Section 20(4)(b))',
                'severity' => 'error'
            ];
        }

        // 7. Recipient details, full vs abridged tax invoice (s20(4)/(5)):
        //    total <= R5,000 qualifies as an ABRIDGED tax invoice — the
        //    recipient's VAT number and address are NOT required, so no noise.
        //    Above R5,000 a FULL tax invoice needs the recipient's VAT number
        //    (when the recipient is a vendor) and address; we cannot know
        //    whether the customer is a vendor, so both stay warnings.
        $total = floatval($invoice['total'] ?? 0);
        if ($total > 5000) {
            if (empty($invoice['customer_vat'])) {
                $issues[] = [
                    'field' => 'customer_vat',
                    'message' => 'Customer VAT number is required on a full tax invoice exceeding R5,000 when the customer is a registered vendor (SARS Section 20(4)(b))',
                    'severity' => 'warning'
                ];
            }
            if (trim((string)($invoice['customer_address1'] ?? '')) === '') {
                $issues[] = [
                    'field' => 'customer_address',
                    'message' => 'Customer address is required on a full tax invoice exceeding R5,000 (SARS Section 20(4)(b)) — add an address to the customer in CRM',
                    'severity' => 'warning'
                ];
            }
        }

        // 8. Check invoice lines
        $stmt = $this->db->prepare(
            "SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY id"
        );
        $stmt->execute([$invoiceId]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($lines)) {
            $issues[] = [
                'field' => 'lines',
                'message' => 'Invoice has no line items (SARS Section 20(4)(e))',
                'severity' => 'error'
            ];
        } else {
            foreach ($lines as $idx => $line) {
                $lineNum = $idx + 1;

                // Description required (item_description is the column in invoice_lines)
                if (empty($line['item_description'])) {
                    $issues[] = [
                        'field' => 'line_' . $lineNum . '_description',
                        'message' => "Line $lineNum: Description of goods/services missing (SARS Section 20(4)(e))",
                        'severity' => 'error'
                    ];
                }

                // VAT rate is persisted per line now — NULL means the line was
                // written outside the sanctioned save paths and the VAT split
                // cannot be derived (s20(4)(f)).
                if (!array_key_exists('tax_rate', $line) || $line['tax_rate'] === null) {
                    $issues[] = [
                        'field' => 'line_' . $lineNum . '_tax_rate',
                        'message' => "Line $lineNum: VAT rate not specified (SARS Section 20(4)(f))",
                        'severity' => 'error'
                    ];
                }
            }
        }

        return $issues;
    }

    /**
     * Check if an invoice is SARS-compliant (no errors; warnings/info allowed).
     *
     * @param int $invoiceId
     * @return bool
     */
    public function isCompliant(int $invoiceId): bool
    {
        $issues = $this->validate($invoiceId);
        foreach ($issues as $issue) {
            if ($issue['severity'] === 'error') {
                return false;
            }
        }
        return true;
    }

    /**
     * The company's custom invoice title, or null when the schema has no such
     * column (older DBs) so the wording check is skipped. Checks
     * companies.qi_invoice_title first, then qi_settings.qi_invoice_title.
     */
    private function fetchInvoiceTitle(): ?string
    {
        if ($this->columnExists('companies', 'qi_invoice_title')) {
            $stmt = $this->db->prepare("SELECT qi_invoice_title FROM companies WHERE id = ?");
            $stmt->execute([$this->companyId]);
            $title = $stmt->fetchColumn();
            return $title === false || $title === null ? null : (string)$title;
        }
        if ($this->columnExists('qi_settings', 'qi_invoice_title')) {
            $stmt = $this->db->prepare("SELECT qi_invoice_title FROM qi_settings WHERE company_id = ?");
            $stmt->execute([$this->companyId]);
            $title = $stmt->fetchColumn();
            return $title === false || $title === null ? null : (string)$title;
        }
        return null;
    }

    private function columnExists(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (!array_key_exists($key, self::$columnCache)) {
            try {
                $stmt = $this->db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
                $stmt->execute([$column]);
                self::$columnCache[$key] = (bool)$stmt->fetch();
            } catch (PDOException $e) {
                self::$columnCache[$key] = false;
            }
        }
        return self::$columnCache[$key];
    }
}
