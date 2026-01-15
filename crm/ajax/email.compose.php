<?php
// /crm/ajax/email.compose.php – API endpoint to send an email from CRM
//
// Accepts POST parameters:
//   crm_account_id  – the CRM account ID to link the email to (customer or supplier)
//   mail_account_id – the mail account ID from which to send (email_accounts.account_id)
//   to             – comma-separated list of recipient email addresses
//   cc, bcc        – optional comma-separated lists
//   subject        – email subject line
//   body           – HTML/plaintext body
//   signature_id   – optional signature to append (email_signatures.signature_id)
//
// The endpoint validates inputs, ensures the current user has access to
// the selected mail account and CRM account, sends the message via
// SmtpSender, stores it locally in the emails table and links it to
// the CRM account in email_links.  On success returns { ok: true }.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../mail/lib/SecureVault.php';
require_once __DIR__ . '/../../mail/lib/SmtpSender.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'] ?? 0;
$userId    = $_SESSION['user_id'] ?? 0;

try {
    // Collect and sanitize POST fields
    $crmAccountId  = isset($_POST['crm_account_id']) ? (int)$_POST['crm_account_id'] : 0;
    $mailAccountId = isset($_POST['mail_account_id']) ? (int)$_POST['mail_account_id'] : 0;
    $toList  = isset($_POST['to']) ? array_filter(array_map('trim', explode(',', $_POST['to']))) : [];
    $ccList  = isset($_POST['cc']) ? array_filter(array_map('trim', explode(',', $_POST['cc']))) : [];
    $bccList = isset($_POST['bcc']) ? array_filter(array_map('trim', explode(',', $_POST['bcc']))) : [];
    $subject = trim($_POST['subject'] ?? '');
    $body    = trim($_POST['body'] ?? '');
    $signatureId = isset($_POST['signature_id']) ? (int)$_POST['signature_id'] : 0;

    // Validate required fields
    if ($crmAccountId <= 0) {
        throw new Exception('CRM account is required');
    }
    if ($mailAccountId <= 0) {
        throw new Exception('Mail account is required');
    }
    if (empty($toList)) {
        throw new Exception('Recipient (to) is required');
    }
    if ($subject === '') {
        throw new Exception('Subject is required');
    }

    // Ensure the CRM account exists and belongs to this company
    $stmt = $DB->prepare("SELECT id, type FROM crm_accounts WHERE id = ? AND company_id = ?");
    $stmt->execute([$crmAccountId, $companyId]);
    $crmAcc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$crmAcc) {
        throw new Exception('Invalid CRM account');
    }
    $linkedType = $crmAcc['type']; // expected 'customer' or 'supplier'

    // Ensure the user owns the mail account and it is active
    $stmt = $DB->prepare("SELECT * FROM email_accounts WHERE account_id = ? AND company_id = ? AND user_id = ? AND is_active = 1");
    $stmt->execute([$mailAccountId, $companyId, $userId]);
    $mailAcc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mailAcc) {
        throw new Exception('Mail account not found or inactive');
    }

    // Append signature if provided
    if ($signatureId > 0) {
        $sigStmt = $DB->prepare("SELECT html FROM email_signatures WHERE signature_id = ? AND company_id = ? AND (user_id = ? OR user_id IS NULL)");
        $sigStmt->execute([$signatureId, $companyId, $userId]);
        $sigHtml = $sigStmt->fetchColumn();
        if ($sigHtml) {
            if ($body && substr($body, -4) !== '</p>') {
                $body .= "<br><br>";
            }
            $body .= $sigHtml;
        }
    }

    // Decrypt mail account password
    $passwordPlain = SecureVault::decrypt($mailAcc['password_encrypted'] ?? '');
    if (!$passwordPlain) {
        throw new Exception('Unable to decrypt mail account password');
    }

    // Send the message
    $send = SmtpSender::send([
        'host'       => $mailAcc['smtp_server'],
        'port'       => (int)$mailAcc['smtp_port'],
        'encryption' => $mailAcc['smtp_encryption'],
        'username'   => $mailAcc['username'],
        'password'   => $passwordPlain,
        'from'       => $mailAcc['email_address'],
        'to'         => $toList,
        'cc'         => $ccList,
        'bcc'        => $bccList,
        'subject'    => $subject,
        'html'       => $body
    ]);
    if (!$send['ok']) {
        $err = isset($send['error']) ? $send['error'] : 'Send failed';
        throw new Exception($err);
    }

    // Store locally in the Sent folder
    $toStr  = implode(',', $toList);
    $ccStr  = implode(',', $ccList);
    $bccStr = implode(',', $bccList);
    $sender = $mailAcc['email_address'];
    // Use thread_id = 0 for new thread
    $stmt = $DB->prepare(
        "INSERT INTO emails (company_id, account_id, thread_id, folder, direction, sender, to_recipients, cc_recipients, bcc_recipients, subject, body_html, body_text, sent_at, is_read, created_at) " .
        "VALUES (?, ?, 0, 'Sent', 'outgoing', ?, ?, ?, ?, ?, ?, NOW(), 1, NOW())"
    );
    $stmt->execute([
        $companyId,
        $mailAccountId,
        $sender,
        $toStr,
        $ccStr,
        $bccStr,
        $subject,
        $body,
        strip_tags($body)
    ]);
    $emailId = (int)$DB->lastInsertId();

    // Link the email to the CRM account
    $linkStmt = $DB->prepare(
        "INSERT INTO email_links (company_id, email_id, linked_type, linked_id, created_by, created_at) " .
        "VALUES (?, ?, ?, ?, ?, NOW())"
    );
    $linkStmt->execute([$companyId, $emailId, $linkedType, $crmAccountId, $userId]);

    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    error_log('CRM email compose error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}