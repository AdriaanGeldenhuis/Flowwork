<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

header('Content-Type: application/json');

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];

// Check admin access
$stmt = $DB->prepare("SELECT role FROM users WHERE id = ? AND company_id = ?");
$stmt->execute([$userId, $companyId]);
$user = $stmt->fetch();

if ($user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        
        // ========== ADD USER ==========
        case 'add_user':
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $role = trim($_POST['role'] ?? 'member');
            $status = trim($_POST['status'] ?? 'active');
            $isSeat = isset($_POST['is_seat']) ? 1 : 0;
            
            if (empty($firstName) || empty($lastName) || empty($email)) {
                throw new Exception('Name and email are required');
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Invalid email address');
            }
            
            // Check if email exists
            $stmt = $DB->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                throw new Exception('Email already exists');
            }
            
            // Check seat limit
            if ($isSeat) {
                $stmt = $DB->prepare("
                    SELECT COUNT(*) as seat_count
                    FROM users 
                    WHERE company_id = ? AND is_seat = 1 AND status = 'active'
                ");
                $stmt->execute([$companyId]);
                $seatCount = $stmt->fetchColumn();
                
                $stmt = $DB->prepare("
                    SELECT p.max_users 
                    FROM companies c 
                    JOIN plans p ON p.id = c.plan_id 
                    WHERE c.id = ?
                ");
                $stmt->execute([$companyId]);
                $maxUsers = $stmt->fetchColumn();
                
                if ($seatCount >= $maxUsers) {
                    throw new Exception('User limit reached for your plan');
                }
            }
            
            // Generate temporary password
            $tempPassword = bin2hex(random_bytes(8));
            $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
            
            $stmt = $DB->prepare("
                INSERT INTO users (company_id, email, password_hash, first_name, last_name, role, is_seat, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$companyId, $email, $passwordHash, $firstName, $lastName, $role, $isSeat, $status]);
            $newUserId = $DB->lastInsertId();
            
            // Log audit
            $stmt = $DB->prepare("
                INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
                VALUES (?, ?, 'user_created', ?, NOW())
            ");
            $stmt->execute([$companyId, $userId, "Created user: $email"]);
            
            // TODO: Send welcome email with temp password
            
            echo json_encode([
                'success' => true, 
                'user_id' => $newUserId,
                'temp_password' => $tempPassword
            ]);
            break;
            
        // ========== UPDATE USER ==========
        case 'update_user':
            $targetUserId = (int)($_POST['user_id'] ?? 0);
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $role = trim($_POST['role'] ?? '');
            $status = trim($_POST['status'] ?? '');
            $isSeat = isset($_POST['is_seat']) ? 1 : 0;
            
            if (!$targetUserId) {
                throw new Exception('User ID required');
            }
            
            $stmt = $DB->prepare("
                UPDATE users 
                SET first_name = ?, last_name = ?, role = ?, status = ?, is_seat = ?, updated_at = NOW()
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([$firstName, $lastName, $role, $status, $isSeat, $targetUserId, $companyId]);
            
            // Log audit
            $stmt = $DB->prepare("
                INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
                VALUES (?, ?, 'user_updated', ?, NOW())
            ");
            $stmt->execute([$companyId, $userId, "Updated user ID: $targetUserId"]);
            
            echo json_encode(['success' => true]);
            break;
            
        // ========== DELETE USER ==========
        case 'delete_user':
            $targetUserId = (int)($_POST['user_id'] ?? 0);
            
            if (!$targetUserId || $targetUserId == $userId) {
                throw new Exception('Cannot delete yourself');
            }
            
            // Soft delete: set status to suspended
            $stmt = $DB->prepare("
                UPDATE users 
                SET status = 'suspended', session_token = NULL, updated_at = NOW()
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([$targetUserId, $companyId]);
            
            // Log audit
            $stmt = $DB->prepare("
                INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
                VALUES (?, ?, 'user_deleted', ?, NOW())
            ");
            $stmt->execute([$companyId, $userId, "Removed user ID: $targetUserId"]);
            
            echo json_encode(['success' => true]);
            break;
            
        // ========== CHANGE PLAN ==========
        case 'change_plan':
            $newPlanId = (int)($_POST['plan_id'] ?? 0);
            
            if (!$newPlanId) {
                throw new Exception('Plan ID required');
            }
            
            // Fetch new plan
            $stmt = $DB->prepare("SELECT * FROM plans WHERE id = ?");
            $stmt->execute([$newPlanId]);
            $newPlan = $stmt->fetch();
            
            if (!$newPlan) {
                throw new Exception('Invalid plan');
            }
            
            // Check if downgrade would violate limits
            $stmt = $DB->prepare("SELECT COUNT(*) FROM users WHERE company_id = ? AND is_seat = 1 AND status = 'active'");
            $stmt->execute([$companyId]);
            $currentUsers = $stmt->fetchColumn();
            
            if ($currentUsers > $newPlan['max_users']) {
                throw new Exception("Cannot downgrade: you have $currentUsers users but plan allows {$newPlan['max_users']}");
            }
            
            // Update company plan
            $stmt = $DB->prepare("
                UPDATE companies 
                SET plan_id = ?, max_users = ?, max_companies = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$newPlan['id'], $newPlan['max_users'], $newPlan['max_companies'], $companyId]);
            
            // Update subscription
            $stmt = $DB->prepare("
                UPDATE subscriptions 
                SET plan_id = ?, updated_at = NOW()
                WHERE company_id = ?
            ");
            $stmt->execute([$newPlan['id'], $companyId]);
            
            // Log audit
            $stmt = $DB->prepare("
                INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
                VALUES (?, ?, 'plan_changed', ?, NOW())
            ");
            $stmt->execute([$companyId, $userId, "Changed plan to: {$newPlan['name']}"]);
            
            echo json_encode(['success' => true]);
            break;
            
        // ========== CANCEL SUBSCRIPTION ==========
        case 'cancel_subscription':
            $stmt = $DB->prepare("
                UPDATE subscriptions 
                SET status = 'canceled', updated_at = NOW()
                WHERE company_id = ?
            ");
            $stmt->execute([$companyId]);
            
            // Log audit
            $stmt = $DB->prepare("
                INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
                VALUES (?, ?, 'subscription_canceled', 'User requested cancellation', NOW())
            ");
            $stmt->execute([$companyId, $userId]);
            
            echo json_encode(['success' => true]);
            break;
            
        default:
            throw new Exception('Unknown action');

	// ========== REVOKE INVITE ==========
        case 'revoke_invite':
            $inviteId = (int)($_POST['invite_id'] ?? 0);
            
            if (!$inviteId) {
                throw new Exception('Invite ID required');
            }
            
            $stmt = $DB->prepare("
                DELETE FROM invites 
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([$inviteId, $companyId]);
            
            // Log audit
            $stmt = $DB->prepare("
                INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
                VALUES (?, ?, 'invite_revoked', ?, NOW())
            ");
            $stmt->execute([$companyId, $userId, "Revoked invite ID: $inviteId"]);
            
            echo json_encode(['success' => true]);
            break;
            
        // ========== ARCHIVE BOARD ==========
        case 'archive_board':
            $boardId = (int)($_POST['board_id'] ?? 0);
            
            if (!$boardId) {
                throw new Exception('Board ID required');
            }
            
            $stmt = $DB->prepare("
                UPDATE project_boards 
                SET archived = 1, updated_at = NOW()
                WHERE board_id = ? AND company_id = ?
            ");
            $stmt->execute([$boardId, $companyId]);
            
            // Log audit
            $stmt = $DB->prepare("
                INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
                VALUES (?, ?, 'board_archived', ?, NOW())
            ");
            $stmt->execute([$companyId, $userId, "Archived board ID: $boardId"]);
            
            echo json_encode(['success' => true]);
            break;
            
        // ========== EXPORT BOARD ==========
        case 'export_board':
            $boardId = (int)($_GET['board_id'] ?? 0);
            
            if (!$boardId) {
                throw new Exception('Board ID required');
            }
            
            // Fetch board items
            $stmt = $DB->prepare("
                SELECT bi.*, bg.name as group_name, u.first_name, u.last_name
                FROM board_items bi
                LEFT JOIN board_groups bg ON bg.id = bi.group_id
                LEFT JOIN users u ON u.id = bi.assigned_to
                WHERE bi.board_id = ? AND bi.company_id = ?
                ORDER BY bi.position
            ");
            $stmt->execute([$boardId, $companyId]);
            $items = $stmt->fetchAll();
            
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="board-' . $boardId . '-export-' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Group', 'Title', 'Status', 'Assigned To', 'Due Date', 'Created']);
            
            foreach ($items as $item) {
                fputcsv($output, [
                    $item['group_name'],
                    $item['title'],
                    $item['status_label'],
                    $item['first_name'] ? $item['first_name'] . ' ' . $item['last_name'] : '',
                    $item['due_date'],
                    $item['created_at']
                ]);
            }
            
            fclose($output);
            exit;
            
        // ========== CREATE API KEY ==========
        case 'create_api_key':
            $name = trim($_POST['name'] ?? '');
            
            if (empty($name)) {
                throw new Exception('Name is required');
            }
            
            // Generate token
            $token = 'sk_' . bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            
            $stmt = $DB->prepare("
                INSERT INTO api_keys (company_id, name, token_hash, scopes, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$companyId, $name, $tokenHash, json_encode(['read', 'write']), $userId]);
            
            // Log audit
            $stmt = $DB->prepare("
                INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
                VALUES (?, ?, 'api_key_created', ?, NOW())
            ");
            $stmt->execute([$companyId, $userId, "Created API key: $name"]);
            
            echo json_encode(['success' => true, 'api_key' => $token]);
            break;
            
        // ========== REVOKE API KEY ==========
        case 'revoke_api_key':
            $keyId = (int)($_POST['key_id'] ?? 0);
            
            $stmt = $DB->prepare("
                UPDATE api_keys 
                SET revoked_at = NOW()
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([$keyId, $companyId]);
            
            // Log audit
            $stmt = $DB->prepare("
                INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
                VALUES (?, ?, 'api_key_revoked', ?, NOW())
            ");
            $stmt->execute([$companyId, $userId, "Revoked API key ID: $keyId"]);
            
            echo json_encode(['success' => true]);
            break;
            
        // ========== CREATE WEBHOOK ==========
        case 'create_webhook':
            $url = trim($_POST['url'] ?? '');
            
            if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                throw new Exception('Valid URL is required');
            }
            
            $secret = bin2hex(random_bytes(16));
            
            $stmt = $DB->prepare("
                INSERT INTO webhooks (company_id, url, secret, events_json, active, created_by, created_at)
                VALUES (?, ?, ?, ?, 1, ?, NOW())
            ");
            $stmt->execute([$companyId, $url, $secret, json_encode(['*']), $userId]);
            
            // Log audit
            $stmt = $DB->prepare("
                INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
                VALUES (?, ?, 'webhook_created', ?, NOW())
            ");
            $stmt->execute([$companyId, $userId, "Created webhook: $url"]);
            
            echo json_encode(['success' => true]);
            break;
            
        // ========== TEST WEBHOOK ==========
        case 'test_webhook':
            $webhookId = (int)($_POST['webhook_id'] ?? 0);
            
            $stmt = $DB->prepare("SELECT * FROM webhooks WHERE id = ? AND company_id = ?");
            $stmt->execute([$webhookId, $companyId]);
            $webhook = $stmt->fetch();
            
            if (!$webhook) {
                throw new Exception('Webhook not found');
            }
            
            // Send test ping
            $payload = json_encode([
                'event' => 'ping',
                'timestamp' => time(),
                'company_id' => $companyId
            ]);
            
            $signature = hash_hmac('sha256', $payload, $webhook['secret']);
            
            $ch = curl_init($webhook['url']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-Webhook-Signature: ' . $signature
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            // Update last delivery
            $stmt = $DB->prepare("
                UPDATE webhooks 
                SET last_delivery_at = NOW(), last_status = ?
                WHERE id = ?
            ");
            $stmt->execute([$httpCode, $webhookId]);
            
            echo json_encode(['success' => true, 'status' => $httpCode]);
            break;
            
        // ========== DELETE WEBHOOK ==========
        case 'delete_webhook':
            $webhookId = (int)($_POST['webhook_id'] ?? 0);
            
            $stmt = $DB->prepare("
                DELETE FROM webhooks 
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([$webhookId, $companyId]);
            
            // Log audit
            $stmt = $DB->prepare("
                INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
                VALUES (?, ?, 'webhook_deleted', ?, NOW())
            ");
            $stmt->execute([$companyId, $userId, "Deleted webhook ID: $webhookId"]);
            
            echo json_encode(['success' => true]);
            break;
            
        // ========== EXPORT AUDIT LOG ==========
        case 'export_audit':
            // Use same filters as audit.php
            $filterUser = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
            $filterAction = isset($_GET['action']) ? trim($_GET['action']) : null;
            $filterDateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : null;
            $filterDateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : null;
            
            $sql = "
                SELECT al.*, u.first_name, u.last_name, u.email
                FROM audit_log al
                LEFT JOIN users u ON u.id = al.user_id
                WHERE al.company_id = ?
            ";
            $params = [$companyId];
            
            if ($filterUser) {
                $sql .= " AND al.user_id = ?";
                $params[] = $filterUser;
            }
            if ($filterAction) {
                $sql .= " AND al.action LIKE ?";
                $params[] = "%$filterAction%";
            }
            if ($filterDateFrom) {
                $sql .= " AND DATE(al.timestamp) >= ?";
                $params[] = $filterDateFrom;
            }
            if ($filterDateTo) {
                $sql .= " AND DATE(al.timestamp) <= ?";
                $params[] = $filterDateTo;
            }
            
            $sql .= " ORDER BY al.timestamp DESC LIMIT 10000";
            
            $stmt = $DB->prepare($sql);
            $stmt->execute($params);
            $logs = $stmt->fetchAll();
            
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="audit-log-' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Timestamp', 'User', 'Email', 'Action', 'Details', 'IP']);
            
            foreach ($logs as $log) {
                fputcsv($output, [
                    $log['timestamp'],
                    $log['first_name'] . ' ' . $log['last_name'],
                    $log['email'],
                    $log['action'],
                    $log['details'],
                    $log['ip']
                ]);
            }
            
            fclose($output);
            exit;
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}