<?php
// /crm/ajax/email_link_suggest.php
// Suggests CRM accounts based on an input email address.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

// Accept email via GET or POST
$inputEmail = '';
if (!empty($_GET['email'])) {
    $inputEmail = trim($_GET['email']);
} elseif (!empty($_POST['email'])) {
    $inputEmail = trim($_POST['email']);
}

try {
    if ($inputEmail === '') {
        throw new Exception('Email is required');
    }

    $normalized = strtolower($inputEmail);
    // Extract domain part for fallback
    $parts = explode('@', $normalized);
    $domain = count($parts) === 2 ? $parts[1] : '';

    $matches = [];

    // 1. Exact contact email matches
    $exactStmt = $DB->prepare(
        "SELECT c.account_id, a.name
         FROM crm_contacts c
         JOIN crm_accounts a ON a.id = c.account_id AND a.company_id = c.company_id
         WHERE c.company_id = ? AND LOWER(c.email) = ?"
    );
    $exactStmt->execute([$companyId, $normalized]);
    while ($row = $exactStmt->fetch(PDO::FETCH_ASSOC)) {
        $accId = (int)$row['account_id'];
        $matches[$accId] = [
            'account_id' => $accId,
            'name' => $row['name'],
            'score' => 1.0,
            'reason' => 'Exact contact email match'
        ];
    }

    // 2. Domain-based matches on contacts
    if ($domain !== '') {
        $domParam = '%@' . $domain;
        $domainStmt = $DB->prepare(
            "SELECT DISTINCT c.account_id, a.name
             FROM crm_contacts c
             JOIN crm_accounts a ON a.id = c.account_id AND a.company_id = c.company_id
             WHERE c.company_id = ? AND LOWER(c.email) LIKE ?"
        );
        $domainStmt->execute([$companyId, $domParam]);
        while ($row = $domainStmt->fetch(PDO::FETCH_ASSOC)) {
            $accId = (int)$row['account_id'];
            if (!isset($matches[$accId])) {
                $matches[$accId] = [
                    'account_id' => $accId,
                    'name' => $row['name'],
                    'score' => 0.6,
                    'reason' => 'Domain match on contact email'
                ];
            }
        }
    }

    // 3. Domain-based matches on account email and website
    if ($domain !== '') {
        $domLike = '%' . $domain . '%';
        $accStmt = $DB->prepare(
            "SELECT a.id, a.name, a.email, a.website
             FROM crm_accounts a
             WHERE a.company_id = ? AND (LOWER(a.email) LIKE ? OR LOWER(a.website) LIKE ?)"
        );
        $accStmt->execute([$companyId, $domLike, $domLike]);
        while ($row = $accStmt->fetch(PDO::FETCH_ASSOC)) {
            $accId = (int)$row['id'];
            // Skip if already matched
            if (isset($matches[$accId])) continue;
            $matches[$accId] = [
                'account_id' => $accId,
                'name' => $row['name'],
                'score' => 0.5,
                'reason' => 'Domain match on account'
            ];
        }
    }

    // Sort matches by score descending
    usort($matches, function($a, $b) {
        if ($a['score'] === $b['score']) return 0;
        return ($a['score'] > $b['score']) ? -1 : 1;
    });
    // Limit to top 5
    $matches = array_slice($matches, 0, 5);

    echo json_encode(['ok' => true, 'matches' => $matches]);

} catch (Exception $e) {
    error_log('CRM email_link_suggest error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
