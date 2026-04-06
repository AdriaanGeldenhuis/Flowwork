<?php
/**
 * /qi/ajax/download_pdf.php — Generate and stream a styled PDF for download.
 *
 * Usage: GET ?type=invoice|quote|credit_note&id=123
 * Streams the PDF directly as a file download (Content-Disposition: attachment).
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../includes/pdf/qi_styled_pdf.php';

$companyId = $_SESSION['company_id'];
$type      = $_GET['type'] ?? 'invoice';
$id        = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    http_response_code(400);
    echo 'Invalid document ID';
    exit;
}

function fmtAmount($amount) {
    return 'R ' . number_format((float)$amount, 2);
}

try {
    if ($type === 'quote') {
        $stmt = $DB->prepare(
            "SELECT q.*,
                    c.name AS company_name, c.logo_url, c.vat_number, c.tax_number, c.reg_number,
                    c.website, c.phone AS company_phone, c.email AS company_email,
                    c.address_line1 AS company_address1, c.address_line2 AS company_address2,
                    c.city AS company_city, c.region AS company_region, c.postal AS company_postal,
                    c.bank_name, c.bank_account_number, c.bank_branch_code,
                    ca.name AS customer_name, ca.email AS customer_email, ca.phone AS customer_phone,
                    p.name AS project_name
             FROM quotes q
             LEFT JOIN companies c ON q.company_id = c.id
             LEFT JOIN crm_accounts ca ON q.customer_id = ca.id
             LEFT JOIN projects p ON q.project_id = p.project_id
             WHERE q.id = ? AND q.company_id = ?"
        );
        $stmt->execute([$id, $companyId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc) { throw new Exception('Quote not found'); }

        $doc['_doc_type'] = 'QUOTE';
        $doc['_doc_title'] = 'Quote #: ' . $doc['quote_number'];
        $doc['_dates'] = [
            'Issue Date' => date('d M Y', strtotime($doc['issue_date'])),
            'Expires'    => date('d M Y', strtotime($doc['expiry_date'])),
            'Status'     => ucfirst($doc['status']),
        ];
        $filename = $doc['quote_number'] . '.pdf';

        $stmt = $DB->prepare("SELECT item_description, quantity, unit_price, line_total FROM quote_lines WHERE quote_id = ? ORDER BY sort_order");
        $stmt->execute([$id]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($type === 'credit_note') {
        $stmt = $DB->prepare(
            "SELECT cn.*,
                    c.name AS company_name, c.logo_url, c.vat_number, c.tax_number, c.reg_number,
                    c.website, c.phone AS company_phone, c.email AS company_email,
                    c.address_line1 AS company_address1, c.address_line2 AS company_address2,
                    c.city AS company_city, c.region AS company_region, c.postal AS company_postal,
                    c.bank_name, c.bank_account_number, c.bank_branch_code,
                    ca.name AS customer_name, ca.email AS customer_email, ca.phone AS customer_phone,
                    i.invoice_number AS linked_invoice_number
             FROM credit_notes cn
             LEFT JOIN companies c ON cn.company_id = c.id
             LEFT JOIN crm_accounts ca ON cn.customer_id = ca.id
             LEFT JOIN invoices i ON cn.invoice_id = i.id
             WHERE cn.id = ? AND cn.company_id = ?"
        );
        $stmt->execute([$id, $companyId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc) { throw new Exception('Credit note not found'); }

        $doc['project_name'] = null;
        $doc['_doc_type'] = 'CREDIT NOTE';
        $doc['_doc_title'] = 'Credit Note #: ' . $doc['credit_note_number'];
        $dates = [
            'Issue Date' => date('d M Y', strtotime($doc['issue_date'])),
            'Status'     => ucfirst($doc['status']),
        ];
        if (!empty($doc['linked_invoice_number'])) {
            $dates['Invoice'] = $doc['linked_invoice_number'];
        }
        $doc['_dates'] = $dates;
        $filename = $doc['credit_note_number'] . '.pdf';

        $stmt = $DB->prepare("SELECT item_description, quantity, unit_price, line_total FROM credit_note_lines WHERE credit_note_id = ? ORDER BY sort_order");
        $stmt->execute([$id]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } else {
        // Invoice (default)
        $stmt = $DB->prepare(
            "SELECT i.*,
                    c.name AS company_name, c.logo_url, c.vat_number, c.tax_number, c.reg_number,
                    c.website, c.phone AS company_phone, c.email AS company_email,
                    c.address_line1 AS company_address1, c.address_line2 AS company_address2,
                    c.city AS company_city, c.region AS company_region, c.postal AS company_postal,
                    c.bank_name, c.bank_account_number, c.bank_branch_code,
                    ca.name AS customer_name, ca.email AS customer_email, ca.phone AS customer_phone,
                    p.name AS project_name
             FROM invoices i
             LEFT JOIN companies c ON i.company_id = c.id
             LEFT JOIN crm_accounts ca ON i.customer_id = ca.id
             LEFT JOIN projects p ON i.project_id = p.project_id
             WHERE i.id = ? AND i.company_id = ?"
        );
        $stmt->execute([$id, $companyId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc) { throw new Exception('Invoice not found'); }

        $doc['_doc_type'] = 'INVOICE';
        $doc['_doc_title'] = 'Invoice #: ' . $doc['invoice_number'];
        $doc['_dates'] = [
            'Issue Date' => date('d M Y', strtotime($doc['issue_date'])),
            'Due Date'   => date('d M Y', strtotime($doc['due_date'])),
            'Status'     => ucfirst($doc['status']),
        ];
        $filename = $doc['invoice_number'] . '.pdf';

        $stmt = $DB->prepare("SELECT item_description, quantity, unit_price, line_total FROM invoice_lines WHERE invoice_id = ? ORDER BY sort_order");
        $stmt->execute([$id]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Generate PDF in memory
    $pdf = new QiStyledPdfWriter($doc, $lines);
    $pdfContent = $pdf->render();

    // Stream as download
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfContent));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $pdfContent;

} catch (Exception $e) {
    http_response_code(500);
    echo 'Error: ' . htmlspecialchars($e->getMessage());
}
