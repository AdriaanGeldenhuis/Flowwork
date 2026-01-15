<?php
// /crm/ajax/account_timeline.php
// Aggregate recent events (interactions, emails, quotes, invoices) into a single timeline for an account.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

// Accept account_id via GET for simplicity
$accountId = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;

try {
    if (!$accountId) {
        throw new Exception('account_id is required');
    }
    // Verify account belongs to company
    $stmt = $DB->prepare("SELECT id FROM crm_accounts WHERE id = ? AND company_id = ?");
    $stmt->execute([$accountId, $companyId]);
    if (!$stmt->fetch()) {
        throw new Exception('Account not found');
    }

    $events = [];

    // Fetch interactions
    $intStmt = $DB->prepare("SELECT i.id, i.type, i.subject, i.created_at, u.first_name, u.last_name
        FROM crm_interactions i
        LEFT JOIN users u ON u.id = i.created_by
        WHERE i.company_id = ? AND i.account_id = ?");
    $intStmt->execute([$companyId, $accountId]);
    while ($row = $intStmt->fetch(PDO::FETCH_ASSOC)) {
        $title = $row['subject'] ?: ucfirst($row['type']);
        $by = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $events[] = [
            'type' => 'interaction',
            'id' => (int)$row['id'],
            'title' => $title,
            'ts' => $row['created_at'] ? date('Y-m-d H:i', strtotime($row['created_at'])) : '',
            'by' => $by
        ];
    }

    // Fetch emails linked to this account (ignore those without sent_at)
    $emailStmt = $DB->prepare("SELECT email_id, subject, sent_at, sender
        FROM emails
        WHERE company_id = ? AND account_id = ? AND sent_at IS NOT NULL");
    $emailStmt->execute([$companyId, $accountId]);
    while ($row = $emailStmt->fetch(PDO::FETCH_ASSOC)) {
        $title = $row['subject'] ?: 'Email';
        $by = $row['sender'] ?: '';
        $events[] = [
            'type' => 'email',
            'id' => (int)$row['email_id'],
            'title' => $title,
            'ts' => $row['sent_at'] ? date('Y-m-d H:i', strtotime($row['sent_at'])) : '',
            'by' => $by
        ];
    }

    // Fetch quotes for this account
    $quoteStmt = $DB->prepare("SELECT q.id, q.quote_number, q.created_at, u.first_name, u.last_name
        FROM quotes q
        LEFT JOIN users u ON u.id = q.created_by
        WHERE q.company_id = ? AND q.customer_id = ?");
    $quoteStmt->execute([$companyId, $accountId]);
    while ($row = $quoteStmt->fetch(PDO::FETCH_ASSOC)) {
        $title = 'Quote ' . $row['quote_number'];
        $by = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $events[] = [
            'type' => 'quote',
            'id' => (int)$row['id'],
            'title' => $title,
            'ts' => $row['created_at'] ? date('Y-m-d H:i', strtotime($row['created_at'])) : '',
            'by' => $by
        ];
    }

    // Fetch invoices for this account
    $invStmt = $DB->prepare("SELECT i.id, i.invoice_number, i.created_at, u.first_name, u.last_name
        FROM invoices i
        LEFT JOIN users u ON u.id = i.created_by
        WHERE i.company_id = ? AND i.customer_id = ?");
    $invStmt->execute([$companyId, $accountId]);
    while ($row = $invStmt->fetch(PDO::FETCH_ASSOC)) {
        $title = 'Invoice ' . $row['invoice_number'];
        $by = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $events[] = [
            'type' => 'invoice',
            'id' => (int)$row['id'],
            'title' => $title,
            'ts' => $row['created_at'] ? date('Y-m-d H:i', strtotime($row['created_at'])) : '',
            'by' => $by
        ];
    }

    // Sort events by timestamp descending
    usort($events, function($a, $b) {
        return strcmp($b['ts'], $a['ts']);
    });
    // Fetch timeline limit from settings (default 50)
    $limit = 50;
    try {
        $limitStmt = $DB->prepare("SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = 'crm_timeline_limit'");
        $limitStmt->execute([$companyId]);
        $limVal = $limitStmt->fetchColumn();
        if ($limVal !== false && is_numeric($limVal)) {
            $limit = (int)$limVal;
            if ($limit < 10 || $limit > 500) {
                $limit = 50;
            }
        }
    } catch (Exception $e) {
        // keep default
    }
    // Limit events to configured limit
    $events = array_slice($events, 0, $limit);

    echo json_encode($events);

} catch (Exception $e) {
    error_log('CRM account_timeline error: ' . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
