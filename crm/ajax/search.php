<?php
// /crm/ajax/search.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

$type = $_GET['type'] ?? 'supplier';
if (!in_array($type, ['supplier', 'customer'])) {
    $type = 'supplier';
}

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$industry = $_GET['industry'] ?? '';
$region = $_GET['region'] ?? '';

try {
    $sql = "
        SELECT 
            a.id, 
            a.name, 
            a.email, 
            a.phone,
            a.status,
            (SELECT CONCAT(c.first_name, ' ', c.last_name) 
             FROM crm_contacts c 
             WHERE c.account_id = a.id AND c.company_id = a.company_id AND c.is_primary = 1 
             LIMIT 1) as primary_contact
        FROM crm_accounts a
        WHERE a.company_id = ? 
          AND a.type = ?
    ";
    $params = [$companyId, $type];

    if ($search !== '') {
        $sql .= " AND (a.name LIKE ? OR a.email LIKE ? OR a.phone LIKE ?)";
        $searchPattern = '%' . $search . '%';
        $params[] = $searchPattern;
        $params[] = $searchPattern;
        $params[] = $searchPattern;
    }

    if ($status !== '') {
        $sql .= " AND a.status = ?";
        $params[] = $status;
    }

    if ($industry !== '') {
        $sql .= " AND a.industry_id = ?";
        $params[] = $industry;
    }

    if ($region !== '') {
        $sql .= " AND a.region_id = ?";
        $params[] = $region;
    }

    $sql .= " ORDER BY a.name ASC LIMIT 100";

    $stmt = $DB->prepare($sql);
    $stmt->execute($params);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch tags for each account
    foreach ($accounts as &$acc) {
        $tagStmt = $DB->prepare("
            SELECT t.name, t.color 
            FROM crm_tags t
            JOIN crm_account_tags at ON at.tag_id = t.id
            WHERE at.account_id = ?
        ");
        $tagStmt->execute([$acc['id']]);
        $acc['tags'] = $tagStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['ok' => true, 'accounts' => $accounts]);

} catch (Exception $e) {
    error_log("CRM search error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Search failed']);
}