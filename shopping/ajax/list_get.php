<?php
// /shopping/ajax/list_get.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$mode = $input['mode'] ?? 'my'; // 'my' | 'shared' | 'single'
$listId = $input['list_id'] ?? null;
$includeItems = $input['include_items'] ?? false;

try {
    if ($mode === 'single' && $listId) {
        // Get single list with items
        $stmt = $DB->prepare("
            SELECT l.*, u.first_name, u.last_name
            FROM shopping_lists l
            JOIN users u ON l.owner_id = u.id
            WHERE l.id = ? AND l.company_id = ?
        ");
        $stmt->execute([$listId, $companyId]);
        $list = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$list) {
            echo json_encode(['ok' => false, 'error' => 'List not found']);
            exit;
        }
        
        if ($includeItems) {
            $stmt = $DB->prepare("
                SELECT i.*, p.name as project_name
                FROM shopping_items i
                LEFT JOIN projects p ON i.project_id = p.project_id
                WHERE i.list_id = ?
                ORDER BY i.order_index, i.id
            ");
            $stmt->execute([$listId]);
            $list['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        echo json_encode(['ok' => true, 'list' => $list, 'items' => $list['items'] ?? []]);
        
    } else {
        // Get my lists
        $stmt = $DB->prepare("
            SELECT l.*,
                   (SELECT COUNT(*) FROM shopping_items WHERE list_id = l.id) as item_count,
                   (SELECT COUNT(*) FROM shopping_items WHERE list_id = l.id AND status = 'bought') as bought_count,
                   0 as est_total_cents
            FROM shopping_lists l
            WHERE l.company_id = ? 
              AND (l.owner_id = ? OR l.shared_mode IN ('team', 'token'))
            ORDER BY 
              CASE l.status 
                WHEN 'buying' THEN 1 
                WHEN 'open' THEN 2 
                ELSE 3 
              END,
              l.updated_at DESC
            LIMIT 50
        ");
        $stmt->execute([$companyId, $userId]);
        $lists = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format dates
        foreach ($lists as &$list) {
            $list['created_at'] = date('Y-m-d', strtotime($list['created_at']));
        }
        
        echo json_encode(['ok' => true, 'lists' => $lists]);
    }
    
} catch (Exception $e) {
    error_log("List get error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}