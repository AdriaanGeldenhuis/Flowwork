<?php
// /crm/ajax/tag_suggest.php – Suggest tags for a CRM account
// based on email domains and keywords in account notes. Returns
// suggestions as a JSON array of tag objects (id and name).

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

try {
    // Accept account_id via GET or POST
    $accountId = 0;
    if (!empty($_GET['account_id'])) {
        $accountId = (int)$_GET['account_id'];
    } elseif (!empty($_POST['account_id'])) {
        $accountId = (int)$_POST['account_id'];
    }
    if ($accountId <= 0) {
        throw new Exception('account_id is required');
    }
    // Verify that account belongs to company
    $stmtAcc = $DB->prepare("SELECT notes FROM crm_accounts WHERE id = ? AND company_id = ?");
    $stmtAcc->execute([$accountId, $companyId]);
    $accRow = $stmtAcc->fetch(PDO::FETCH_ASSOC);
    if (!$accRow) {
        throw new Exception('Invalid account');
    }
    $notes = $accRow['notes'] ?? '';
    $notesLower = strtolower($notes);
    // Fetch existing tags for the company
    $tagsStmt = $DB->prepare("SELECT id, name FROM crm_tags WHERE company_id = ?");
    $tagsStmt->execute([$companyId]);
    $tags = $tagsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$tags) {
        echo json_encode(['ok' => true, 'suggestions' => []]);
        return;
    }
    // Fetch tags already assigned to this account
    $assignedStmt = $DB->prepare(
        "SELECT t.id
         FROM crm_account_tags at
         JOIN crm_tags t ON t.id = at.tag_id
         WHERE at.account_id = ?"
    );
    $assignedStmt->execute([$accountId]);
    $assignedIds = $assignedStmt->fetchAll(PDO::FETCH_COLUMN);
    $assignedSet = array_flip($assignedIds);
    // Fetch contact emails for domain extraction
    $emailStmt = $DB->prepare(
        "SELECT email FROM crm_contacts WHERE account_id = ? AND company_id = ? AND email IS NOT NULL"
    );
    $emailStmt->execute([$accountId, $companyId]);
    $emails = $emailStmt->fetchAll(PDO::FETCH_COLUMN);
    $domains = [];
    foreach ($emails as $em) {
        $em = strtolower(trim($em));
        $parts = explode('@', $em);
        if (count($parts) === 2) {
            $domain = $parts[1];
            // Strip top-level domain: remove the last two segments if there are more than 2 segments
            $domainParts = explode('.', $domain);
            if (count($domainParts) > 1) {
                // Remove common TLD endings like .co.za, .com, .net by removing last one or two segments
                // Keep the first segment which often contains the company name
                $base = $domainParts[0];
            } else {
                $base = $domain;
            }
            $domains[] = $base;
        }
    }
    // Remove duplicates
    $domains = array_unique($domains);
    $suggestions = [];
    foreach ($tags as $tag) {
        $tagId = (int)$tag['id'];
        $tagName = trim($tag['name']);
        if ($tagName === '' || isset($assignedSet[$tagId])) {
            continue; // Skip empty names and already assigned tags
        }
        $tagLower = strtolower($tagName);
        $matched = false;
        // Check note match
        if ($notesLower !== '' && strpos($notesLower, $tagLower) !== false) {
            $matched = true;
        }
        // Check domain match
        if (!$matched) {
            foreach ($domains as $dom) {
                if ($dom === $tagLower || strpos($dom, $tagLower) !== false || strpos($tagLower, $dom) !== false) {
                    $matched = true;
                    break;
                }
            }
        }
        if ($matched) {
            $suggestions[] = ['id' => $tagId, 'name' => $tagName];
        }
    }
    echo json_encode(['ok' => true, 'suggestions' => $suggestions]);
} catch (Exception $e) {
    error_log('Tag suggest error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
