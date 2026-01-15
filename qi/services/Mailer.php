<?php
// qi/services/Mailer.php
// Adapter for sending emails via the system's mail stack and recording appropriate logs.

class Mailer
{
    /**
     * PDO instance
     * @var PDO
     */
    private $db;

    public function __construct(PDO $pdo)
    {
        $this->db = $pdo;
    }

    /**
     * Normalize a subject by stripping common prefixes (Re:, Fwd:, etc) and
     * converting to lower case. This helps thread grouping.
     *
     * @param string $subject
     * @return string
     */
    private function normalizeSubject($subject)
    {
        // Remove common prefixes and whitespace, then lower-case
        $clean = preg_replace('/^(re:|fw:|fwd:)[\s]*/i', '', $subject);
        return strtolower(trim($clean));
    }

    /**
     * Send a document (quote, invoice, credit note, etc) to a recipient.
     *
     * This method performs the following:
     *  1. Determines the active email account for the company.
     *  2. Creates a new email thread.
     *  3. Inserts an outgoing email into the emails table with the provided body and attachment.
     *  4. Creates any attachment entries in email_attachments.
     *  5. Inserts an entry into qi_email_log for auditing purposes.
     *  6. Inserts a record into email_links linking the email to the underlying document.
     *
     * If no active email account exists, the mail send is skipped but logs are still recorded.
     *
     * @param int    $companyId      ID of the company sending the email
     * @param int    $userId         ID of the user performing the action
     * @param string $entityType     Type of entity ('quote', 'invoice', 'credit_note', etc)
     * @param int    $entityId       ID of the entity being sent
     * @param string $toEmail        Recipient email address
     * @param string $subject        Subject line for the email
     * @param string $htmlBody       HTML body of the email
     * @param string $textBody       Plain text body of the email
     * @param string $attachmentPath Relative path (from web root) to the attachment to include (e.g., '/storage/qi/1/invoice/INV2025-0001.pdf')
     *
     * @return int|null Returns the new email_id or null if send skipped due to missing account
     */
    public function sendDocument($companyId, $userId, $entityType, $entityId, $toEmail, $subject, $htmlBody, $textBody, $attachmentPath = null)
    {
        // Find the first active email account for this company
        $stmt = $this->db->prepare("SELECT account_id, email_address FROM email_accounts WHERE company_id = ? AND is_active = 1 ORDER BY account_id ASC LIMIT 1");
        $stmt->execute([$companyId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        // Prepare the body preview for logs (strip HTML tags and truncate)
        $bodyPreview = mb_substr(trim(strip_tags($textBody)), 0, 500);

        // Always create a row in qi_email_log and email_links regardless of whether an email account exists
        // We will generate email and thread entries only if an account is present

        $emailId    = null;
        $threadId   = null;
        $hasAccount = $account && !empty($account['account_id']);

        if ($hasAccount) {
            $accountId = (int)$account['account_id'];
            $sender    = $account['email_address'];

            // Normalize the subject for thread grouping
            $subjectNorm = $this->normalizeSubject($subject);

            // 1. Create a new email thread
            $stmtThread = $this->db->prepare("INSERT INTO email_threads (company_id, subject, subject_norm, last_message_at, created_at) VALUES (?, ?, ?, NOW(), NOW())");
            $stmtThread->execute([$companyId, $subject, $subjectNorm]);
            $threadId = $this->db->lastInsertId();

            // 2. Insert the email record (outgoing)
            // Mark the email as read (1) because it is sent by us; star flag default 0
            $hasAttachments = $attachmentPath ? 1 : 0;
            $stmtEmail = $this->db->prepare("INSERT INTO emails (company_id, account_id, thread_id, direction, sender, to_recipients, cc_recipients, bcc_recipients, subject, body_html, body_text, sent_at, is_read, is_starred, has_attachments, folder) VALUES (?, ?, ?, 'outgoing', ?, ?, '', '', ?, ?, ?, NOW(), 1, 0, ?, 'Sent')");
            $stmtEmail->execute([
                $companyId,
                $accountId,
                $threadId,
                $sender,
                $toEmail,
                $subject,
                $htmlBody,
                $textBody,
                $hasAttachments
            ]);
            $emailId = $this->db->lastInsertId();

            // 3. Handle attachments
            if ($attachmentPath) {
                $fileName = basename($attachmentPath);
                // Determine absolute path on disk to read file size
                $absPath = null;
                // If path starts with '/', treat relative to project root (web root). Remove leading slash and prefix base dir.
                $rel = ltrim($attachmentPath, '/');
                // compute base directory - assume attachments stored inside qi/storage
                $rootDir = realpath(__DIR__ . '/../..');
                if ($rootDir) {
                    $absPath = $rootDir . '/' . $rel;
                }
                $fileSize = null;
                if ($absPath && file_exists($absPath)) {
                    $fileSize = filesize($absPath);
                }
                $mimeType = 'application/pdf';
                $stmtAttach = $this->db->prepare("INSERT INTO email_attachments (email_id, file_name, file_path, mime_type, file_size, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmtAttach->execute([
                    $emailId,
                    $fileName,
                    $attachmentPath,
                    $mimeType,
                    $fileSize
                ]);
            }
        }

        // 4. Insert into qi_email_log for auditing
        $stmtLog = $this->db->prepare("INSERT INTO qi_email_log (company_id, entity_type, entity_id, to_email, subject, body_preview, sent_at, status) VALUES (?, ?, ?, ?, ?, ?, NOW(), 'sent')");
        $stmtLog->execute([
            $companyId,
            $entityType,
            $entityId,
            $toEmail,
            $subject,
            $bodyPreview
        ]);
        $emailLogId = $this->db->lastInsertId();

        // 5. Link the email (if created) to the document via email_links
        // We use the email_id if one exists; otherwise link the qi_email_log id to represent the conversation
        $linkId = null;
        if ($emailId) {
            $stmtLink = $this->db->prepare("INSERT INTO email_links (email_id, linked_type, linked_id, created_by, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmtLink->execute([$emailId, $entityType, $entityId, $userId]);
            $linkId = $this->db->lastInsertId();
        }

        // Optionally send the email via PHP's mail() function if we have an account
        if ($hasAccount) {
            $headers = "From: {$sender}\r\n" .
                       "Reply-To: {$sender}\r\n" .
                       "MIME-Version: 1.0\r\n" .
                       "Content-Type: text/html; charset=UTF-8";
            // Attempt to send; ignore result. Actual sending via external mail server may be handled elsewhere.
            @mail($toEmail, $subject, $htmlBody, $headers);
        }

        return $emailId;
    }
}