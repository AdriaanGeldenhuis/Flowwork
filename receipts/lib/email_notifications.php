<?php
/**
 * Email Notification Service for Receipts
 */

class ReceiptNotifications {
    private $DB;
    private $companyId;

    public function __construct($DB, $companyId) {
        $this->DB = $DB;
        $this->companyId = $companyId;
    }

    public function notifyBillApproved($invoiceId) {
        // Fetch bookkeepers
        $stmt = $this->DB->prepare("
            SELECT email, first_name FROM users
            WHERE company_id = ? AND role IN ('admin', 'bookkeeper') AND status = 'active'
        ");
        $stmt->execute([$this->companyId]);
        $recipients = $stmt->fetchAll();

        // Fetch invoice details
        $stmt = $this->DB->prepare("
            SELECT i.*, ca.name as supplier_name
            FROM invoices i
            JOIN crm_accounts ca ON ca.id = i.customer_id
            WHERE i.id = ? AND i.company_id = ?
        ");
        $stmt->execute([$invoiceId, $this->companyId]);
        $invoice = $stmt->fetch();

        if (!$invoice) return false;

        // Fetch company
        $stmt = $this->DB->prepare("SELECT name FROM companies WHERE id = ?");
        $stmt->execute([$this->companyId]);
        $company = $stmt->fetch();

        $subject = "New AP Invoice Approved: " . $invoice['invoice_number'];
        $body = "
            <h2>AP Invoice Approved</h2>
            <p><strong>Company:</strong> {$company['name']}</p>
            <p><strong>Supplier:</strong> {$invoice['supplier_name']}</p>
            <p><strong>Invoice #:</strong> {$invoice['invoice_number']}</p>
            <p><strong>Date:</strong> {$invoice['issue_date']}</p>
            <p><strong>Total:</strong> R" . number_format($invoice['total'], 2) . "</p>
            <p><strong>Status:</strong> Posted to AP</p>
            <p><a href='" . $_SERVER['HTTP_HOST'] . "/invoices/view.php?id={$invoiceId}'>View Invoice</a></p>
        ";

        foreach ($recipients as $recipient) {
            $this->sendEmail($recipient['email'], $subject, $body);
        }

        return true;
    }

    public function notifyPolicyException($fileId, $issues) {
        // Fetch admins
        $stmt = $this->DB->prepare("
            SELECT email, first_name FROM users
            WHERE company_id = ? AND role = 'admin' AND status = 'active'
        ");
        $stmt->execute([$this->companyId]);
        $recipients = $stmt->fetchAll();

        // Fetch file
        $stmt = $this->DB->prepare("
            SELECT rf.*, ro.vendor_name, ro.invoice_number, ro.total
            FROM receipt_file rf
            LEFT JOIN receipt_ocr ro ON ro.file_id = rf.file_id
            WHERE rf.file_id = ? AND rf.company_id = ?
        ");
        $stmt->execute([$fileId, $this->companyId]);
        $file = $stmt->fetch();

        if (!$file) return false;

        $subject = "Receipt Policy Exception: " . ($file['invoice_number'] ?? 'Unknown');
        $issuesList = implode("<br>", array_map(fn($i) => "• " . htmlspecialchars($i), $issues));

        $body = "
            <h2>⚠ Receipt Policy Exception</h2>
            <p><strong>Vendor:</strong> {$file['vendor_name']}</p>
            <p><strong>Invoice #:</strong> {$file['invoice_number']}</p>
            <p><strong>Total:</strong> R" . number_format($file['total'], 2) . "</p>
            <h3>Issues:</h3>
            <p>{$issuesList}</p>
            <p><a href='" . $_SERVER['HTTP_HOST'] . "/receipts/review.php?id={$fileId}'>Review Receipt</a></p>
        ";

        foreach ($recipients as $recipient) {
            $this->sendEmail($recipient['email'], $subject, $body);
        }

        return true;
    }

    private function sendEmail($to, $subject, $body) {
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: FocusWorks <noreply@focusworks.app>',
            'Reply-To: noreply@focusworks.app'
        ];

        mail($to, $subject, $body, implode("\r\n", $headers));

        // Log email
        $stmt = $this->DB->prepare("
            INSERT INTO qi_email_log (company_id, entity_type, entity_id, to_email, subject, body_preview, status)
            VALUES (?, 'receipt', 0, ?, ?, ?, 'sent')
        ");
        $stmt->execute([
            $this->companyId,
            $to,
            $subject,
            substr(strip_tags($body), 0, 200)
        ]);
    }
}