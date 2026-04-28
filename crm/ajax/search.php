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
// deleted: '' or '0' = active only (default), '1' = deleted only, 'all' = both
$deletedFilter = $_GET['deleted'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

try {
    $whereSql = " WHERE a.company_id = ? AND a.type = ?";
    $params = [$companyId, $type];

    if ($deletedFilter === '1') {
        $whereSql .= " AND a.deleted_at IS NOT NULL";
    } elseif ($deletedFilter !== 'all') {
        $whereSql .= " AND a.deleted_at IS NULL";
    }

    if ($search !== '') {
        $whereSql .= " AND (a.name LIKE ? OR a.email LIKE ? OR a.phone LIKE ?)";
        $searchPattern = '%' . $search . '%';
        $params[] = $searchPattern;
        $params[] = $searchPattern;
        $params[] = $searchPattern;
    }

    if ($status !== '') {
        $whereSql .= " AND a.status = ?";
        $params[] = $status;
    }

    if ($industry !== '') {
        $whereSql .= " AND a.industry_id = ?";
        $params[] = $industry;
    }

    if ($region !== '') {
        $whereSql .= " AND a.region_id = ?";
        $params[] = $region;
    }

    // Count total matching records for pagination
    $countStmt = $DB->prepare("SELECT COUNT(*) FROM crm_accounts a" . $whereSql);
    $countStmt->execute($params);
    $totalRecords = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRecords / $perPage));

    $sql = "
        SELECT
            a.id,
            a.name,
            a.email,
            a.phone,
            a.status,
            a.deleted_at,
            (SELECT CONCAT(c.first_name, ' ', c.last_name)
             FROM crm_contacts c
             WHERE c.account_id = a.id AND c.company_id = a.company_id AND c.is_primary = 1
             LIMIT 1) as primary_contact
        FROM crm_accounts a" . $whereSql . " ORDER BY a.name ASC LIMIT ? OFFSET ?";
    $params[] = $perPage;
    $params[] = $offset;

    $stmt = $DB->prepare($sql);
    $stmt->execute($params);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch tags for all accounts in a single query (avoids N+1)
    $accountIds = array_column($accounts, 'id');
    $tagsByAccount = [];
    if (!empty($accountIds)) {
        $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
        $tagStmt = $DB->prepare("
            SELECT at.account_id, t.name, t.color
            FROM crm_tags t
            JOIN crm_account_tags at ON at.tag_id = t.id
            WHERE at.account_id IN ($placeholders)
        ");
        $tagStmt->execute($accountIds);
        while ($row = $tagStmt->fetch(PDO::FETCH_ASSOC)) {
            $tagsByAccount[$row['account_id']][] = ['name' => $row['name'], 'color' => $row['color']];
        }
    }
    foreach ($accounts as &$acc) {
        $acc['tags'] = $tagsByAccount[$acc['id']] ?? [];
    }

    echo json_encode([
        'ok' => true,
        'accounts' => $accounts,
        'page' => $page,
        'per_page' => $perPage,
        'total' => $totalRecords,
        'total_pages' => $totalPages
    ]);

} catch (Exception $e) {
    error_log("CRM search error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Search failed']);
}