<?php
// finances/lib/TaxInvoiceValidator.php
//
// Validates that an invoice meets SARS Section 20 tax invoice requirements.
// SARS requires specific fields on all tax invoices:
// - The words "Tax Invoice" or "Credit Note"
// - Seller's name, address, and VAT registration number
// - Buyer's name and (for invoices > R5,000) VAT registration number
// - Serial number (invoice number)
// - Date of issue
// - Description of goods/services per line
// - VAT rate and amount per line
// - Total including VAT

class TaxInvoiceValidator
{
    private $db;
    private $companyId;

    public function __construct(PDO $db, int $companyId)
    {
        $this->db = $db;
        $this->companyId = $companyId;
    }

    /**
     * Validate an invoice for SARS Section 20 compliance.
     * Returns an array of warnings/errors. Empty array = compliant.
     *
     * @param int $invoiceId
     * @return array Array of ['field' => string, 'message' => string, 'severity' => 'error'|'warning']
     */
    public function validate(int $invoiceId): array
    {
        $issues = [];

        // Fetch invoice with customer from crm_accounts
        $stmt = $this->db->prepare(
            "SELECT i.*, c.name AS customer_name, c.vat_no AS customer_vat,
                    c.email AS customer_email
             FROM invoices i
             LEFT JOIN crm_accounts c ON c.id = i.customer_id
             WHERE i.id = ? AND i.company_id = ?"
        );
        $stmt->execute([$invoiceId, $this->companyId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            return [['field' => 'invoice', 'message' => 'Invoice not found', 'severity' => 'error']];
        }

        // Fetch company details
        $stmt = $this->db->prepare("SELECT name, vat_number, reg_number FROM companies WHERE id = ?");
        $stmt->execute([$this->companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        // 1. Seller VAT number
        if (empty($company['vat_number'])) {
            $issues[] = [
                'field' => 'company_vat_number',
                'message' => 'Company VAT registration number not configured (SARS Section 20(4)(a)). Set this in Admin > Finance Settings.',
                'severity' => 'error'
            ];
        }

        // 2. Invoice number (serial number)
        if (empty($invoice['invoice_number'])) {
            $issues[] = [
                'field' => 'invoice_number',
                'message' => 'Invoice number is missing (SARS Section 20(4)(c))',
                'severity' => 'error'
            ];
        }

        // 3. Issue date
        if (empty($invoice['issue_date'])) {
            $issues[] = [
                'field' => 'issue_date',
                'message' => 'Issue date is missing (SARS Section 20(4)(d))',
                'severity' => 'error'
            ];
        }

        // 4. Customer name
        if (empty($invoice['customer_name'])) {
            $issues[] = [
                'field' => 'customer_name',
                'message' => 'Customer name is missing (SARS Section 20(4)(b))',
                'severity' => 'error'
            ];
        }

        // 5. Customer VAT number required for invoices > R5,000 (Section 20(5))
        $total = floatval($invoice['total'] ?? 0);
        if ($total > 5000 && empty($invoice['customer_vat'])) {
            $issues[] = [
                'field' => 'customer_vat',
                'message' => 'Customer VAT number required for invoices exceeding R5,000 (SARS Section 20(5))',
                'severity' => 'warning'
            ];
        }

        // 6. Check invoice lines
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

                // Tax rate should be explicit (not null)
                if (!isset($line['tax_rate'])) {
                    $issues[] = [
                        'field' => 'line_' . $lineNum . '_tax_rate',
                        'message' => "Line $lineNum: VAT rate not specified (SARS Section 20(4)(f))",
                        'severity' => 'warning'
                    ];
                }
            }
        }

        return $issues;
    }

    /**
     * Check if an invoice is SARS-compliant (no errors, warnings allowed).
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
}
