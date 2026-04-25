<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

header('Content-Type: application/json');

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];

require_once __DIR__ . '/../includes/companies.php';
fw_require_admin('json');

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

            // Does this email already belong to a Flowwork user?
            $stmt = $DB->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $existingUserId = $stmt->fetchColumn();

            if ($existingUserId) {
                // If they already have access to this company, surface that.
                $stmt = $DB->prepare("SELECT 1 FROM user_companies WHERE user_id = ? AND company_id = ?");
                $stmt->execute([$existingUserId, $companyId]);
                if ($stmt->fetchColumn()) {
                    throw new Exception('That user is already a member of this company.');
                }
            }

            // Check seat limit (existing or new user counts the same)
            if ($isSeat) {
                $stmt = $DB->prepare("
                    SELECT COUNT(*) FROM user_companies uc
                    JOIN users u ON u.id = uc.user_id
                    WHERE uc.company_id = ? AND u.is_seat = 1 AND u.status = 'active'
                ");
                $stmt->execute([$companyId]);
                $seatCount = (int)$stmt->fetchColumn();

                $stmt = $DB->prepare("
                    SELECT p.max_users FROM companies c
                    JOIN plans p ON p.id = c.plan_id WHERE c.id = ?
                ");
                $stmt->execute([$companyId]);
                $maxUsers = (int)$stmt->fetchColumn();

                if ($seatCount >= $maxUsers) {
                    throw new Exception('User limit reached for your plan');
                }
            }

            $DB->beginTransaction();
            try {
                if ($existingUserId) {
                    // Link the existing Flowwork user to this company. Their global
                    // first/last/status/is_seat stay as-is; the per-company role
                    // lives on user_companies.
                    $stmt = $DB->prepare(
                        "INSERT INTO user_companies (user_id, company_id, role, created_at)
                         VALUES (?, ?, ?, NOW())"
                    );
                    $stmt->execute([$existingUserId, $companyId, $role]);
                    $newUserId   = (int)$existingUserId;
                    $tempPassword = null;
                    $auditMessage = "Linked existing user: $email";
                } else {
                    $tempPassword = bin2hex(random_bytes(8));
                    $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

                    $stmt = $DB->prepare("
                        INSERT INTO users (company_id, email, password_hash, first_name, last_name, role, is_seat, status, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([$companyId, $email, $passwordHash, $firstName, $lastName, $role, $isSeat, $status]);
                    $newUserId = (int)$DB->lastInsertId();

                    $stmt = $DB->prepare(
                        "INSERT INTO user_companies (user_id, company_id, role, created_at)
                         VALUES (?, ?, ?, NOW())"
                    );
                    $stmt->execute([$newUserId, $companyId, $role]);
                    $auditMessage = "Created user: $email";
                }

                $stmt = $DB->prepare("
                    INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
                    VALUES (?, ?, 'user_created', ?, ?, NOW())
                ");
                $stmt->execute([$companyId, $userId, $auditMessage, $_SERVER['REMOTE_ADDR'] ?? '']);

                $DB->commit();
            } catch (Exception $e) {
                $DB->rollBack();
                throw $e;
            }

            echo json_encode([
                'success'       => true,
                'user_id'       => $newUserId,
                'temp_password' => $tempPassword,
                'linked'        => (bool)$existingUserId,
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

            // Verify the target is actually a member of the active company.
            $stmt = $DB->prepare(
                "SELECT u.company_id AS primary_company_id
                 FROM user_companies uc
                 JOIN users u ON u.id = uc.user_id
                 WHERE uc.user_id = ? AND uc.company_id = ?"
            );
            $stmt->execute([$targetUserId, $companyId]);
            $target = $stmt->fetch();
            if (!$target) {
                throw new Exception('User is not a member of this company');
            }

            // Per-company role always lives on user_companies.
            $stmt = $DB->prepare(
                "UPDATE user_companies SET role = ? WHERE user_id = ? AND company_id = ?"
            );
            $stmt->execute([$role, $targetUserId, $companyId]);

            // Identity + global flags (status/is_seat) only mutate when
            // this is the user's primary company.
            if ((int)$target['primary_company_id'] === $companyId) {
                $stmt = $DB->prepare("
                    UPDATE users
                    SET first_name = ?, last_name = ?, role = ?, status = ?, is_seat = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$firstName, $lastName, $role, $status, $isSeat, $targetUserId]);
            } else {
                $stmt = $DB->prepare(
                    "UPDATE users SET first_name = ?, last_name = ?, updated_at = NOW() WHERE id = ?"
                );
                $stmt->execute([$firstName, $lastName, $targetUserId]);
            }

            // Log audit
            $stmt = $DB->prepare("
                INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
                VALUES (?, ?, 'user_updated', ?, ?, NOW())
            ");
            $stmt->execute([$companyId, $userId, "Updated user ID: $targetUserId", $_SERVER['REMOTE_ADDR'] ?? '']);

            echo json_encode(['success' => true]);
            break;

        // ========== GET USER ==========
        case 'get_user':
            $targetUserId = (int)($_GET['user_id'] ?? 0);

            if (!$targetUserId) {
                throw new Exception('User ID required');
            }

            $stmt = $DB->prepare("
                SELECT u.id, u.first_name, u.last_name, u.email, u.status, u.is_seat,
                       uc.role
                FROM user_companies uc
                JOIN users u ON u.id = uc.user_id
                WHERE u.id = ? AND uc.company_id = ?
            ");
            $stmt->execute([$targetUserId, $companyId]);
            $targetUser = $stmt->fetch();

            if (!$targetUser) {
                throw new Exception('User not found');
            }

            echo json_encode(['success' => true, 'user' => $targetUser]);
            break;

        // ========== DELETE USER ==========
        case 'delete_user':
            $targetUserId = (int)($_POST['user_id'] ?? 0);

            if (!$targetUserId || $targetUserId == $userId) {
                throw new Exception('Cannot delete yourself');
            }

            $stmt = $DB->prepare(
                "SELECT u.company_id AS primary_company_id
                 FROM user_companies uc
                 JOIN users u ON u.id = uc.user_id
                 WHERE uc.user_id = ? AND uc.company_id = ?"
            );
            $stmt->execute([$targetUserId, $companyId]);
            $target = $stmt->fetch();
            if (!$target) {
                throw new Exception('User is not a member of this company');
            }

            // Always revoke their access to this company.
            $stmt = $DB->prepare("DELETE FROM user_companies WHERE user_id = ? AND company_id = ?");
            $stmt->execute([$targetUserId, $companyId]);

            // If this WAS their primary company, also suspend the global
            // account (keeps the existing soft-delete behaviour).
            if ((int)$target['primary_company_id'] === $companyId) {
                $stmt = $DB->prepare(
                    "UPDATE users SET status = 'suspended', session_token = NULL, updated_at = NOW() WHERE id = ?"
                );
                $stmt->execute([$targetUserId]);
            }

            // Log audit
            $stmt = $DB->prepare("
                INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
                VALUES (?, ?, 'user_deleted', ?, ?, NOW())
            ");
            $stmt->execute([$companyId, $userId, "Removed user ID: $targetUserId", $_SERVER['REMOTE_ADDR'] ?? '']);

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

            // Check if downgrade would violate limits — count anyone with
            // access to this company via user_companies, not just primary.
            $stmt = $DB->prepare("
                SELECT COUNT(*) FROM user_companies uc
                JOIN users u ON u.id = uc.user_id
                WHERE uc.company_id = ? AND u.is_seat = 1 AND u.status = 'active'
            ");
            $stmt->execute([$companyId]);
            $currentUsers = $stmt->fetchColumn();

            if ($currentUsers > $newPlan['max_users']) {
                throw new Exception("Cannot downgrade: you have $currentUsers users but plan allows {$newPlan['max_users']}");
            }

            $DB->beginTransaction();
            try {
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
                    INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
                    VALUES (?, ?, 'plan_changed', ?, ?, NOW())
                ");
                $stmt->execute([$companyId, $userId, "Changed plan to: {$newPlan['name']}", $_SERVER['REMOTE_ADDR'] ?? '']);

                $DB->commit();
            } catch (Exception $e) {
                $DB->rollBack();
                throw $e;
            }

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
                INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
                VALUES (?, ?, 'subscription_canceled', 'User requested cancellation', ?, NOW())
            ");
            $stmt->execute([$companyId, $userId, $_SERVER['REMOTE_ADDR'] ?? '']);

            echo json_encode(['success' => true]);
            break;

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
                INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
                VALUES (?, ?, 'invite_revoked', ?, ?, NOW())
            ");
            $stmt->execute([$companyId, $userId, "Revoked invite ID: $inviteId", $_SERVER['REMOTE_ADDR'] ?? '']);

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
                SET archived = 1
                WHERE board_id = ? AND company_id = ?
            ");
            $stmt->execute([$boardId, $companyId]);

            // Log audit
            $stmt = $DB->prepare("
                INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
                VALUES (?, ?, 'board_archived', ?, ?, NOW())
            ");
            $stmt->execute([$companyId, $userId, "Archived board ID: $boardId", $_SERVER['REMOTE_ADDR'] ?? '']);

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

        // ========== GET BOARD MEMBERS ==========
        case 'get_board_members':
            $boardId = (int)($_GET['board_id'] ?? 0);

            if (!$boardId) {
                throw new Exception('Board ID required');
            }

            // Verify board belongs to this company
            $stmt = $DB->prepare("SELECT board_id FROM project_boards WHERE board_id = ? AND company_id = ?");
            $stmt->execute([$boardId, $companyId]);
            if (!$stmt->fetch()) {
                throw new Exception('Board not found');
            }

            // Get current board members
            $stmt = $DB->prepare("
                SELECT bm.*, u.first_name, u.last_name, u.email
                FROM board_members bm
                JOIN users u ON u.id = bm.user_id
                WHERE bm.board_id = ?
                ORDER BY bm.role DESC, u.first_name
            ");
            $stmt->execute([$boardId]);
            $members = $stmt->fetchAll();

            // Get all company users not on the board
            $stmt = $DB->prepare("
                SELECT id, first_name, last_name, email
                FROM users
                WHERE company_id = ? AND status = 'active'
                AND id NOT IN (SELECT user_id FROM board_members WHERE board_id = ?)
                ORDER BY first_name
            ");
            $stmt->execute([$companyId, $boardId]);
            $availableUsers = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'members' => $members,
                'available_users' => $availableUsers
            ]);
            break;

        // ========== UPDATE BOARD MEMBER ==========
        case 'update_board_member':
            $boardId = (int)($_POST['board_id'] ?? 0);
            $memberId = (int)($_POST['user_id'] ?? 0);
            $role = trim($_POST['role'] ?? 'editor');

            if (!$boardId || !$memberId) {
                throw new Exception('Board ID and User ID required');
            }

            // Verify board belongs to this company
            $stmt = $DB->prepare("SELECT board_id FROM project_boards WHERE board_id = ? AND company_id = ?");
            $stmt->execute([$boardId, $companyId]);
            if (!$stmt->fetch()) {
                throw new Exception('Board not found');
            }

            $stmt = $DB->prepare("
                UPDATE board_members SET role = ? WHERE board_id = ? AND user_id = ?
            ");
            $stmt->execute([$role, $boardId, $memberId]);

            // Log audit
            $stmt = $DB->prepare("
                INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
                VALUES (?, ?, 'board_member_updated', ?, ?, NOW())
            ");
            $stmt->execute([$companyId, $userId, "Changed user $memberId role to $role on board $boardId", $_SERVER['REMOTE_ADDR'] ?? '']);

            echo json_encode(['success' => true]);
            break;

        // ========== ADD BOARD MEMBER ==========
        case 'add_board_member':
            $boardId = (int)($_POST['board_id'] ?? 0);
            $memberId = (int)($_POST['user_id'] ?? 0);
            $role = trim($_POST['role'] ?? 'editor');

            if (!$boardId || !$memberId) {
                throw new Exception('Board ID and User ID required');
            }

            // Verify board belongs to this company
            $stmt = $DB->prepare("SELECT board_id FROM project_boards WHERE board_id = ? AND company_id = ?");
            $stmt->execute([$boardId, $companyId]);
            if (!$stmt->fetch()) {
                throw new Exception('Board not found');
            }

            // Verify user belongs to this company
            $stmt = $DB->prepare("SELECT id FROM users WHERE id = ? AND company_id = ?");
            $stmt->execute([$memberId, $companyId]);
            if (!$stmt->fetch()) {
                throw new Exception('User not found');
            }

            $stmt = $DB->prepare("
                INSERT INTO board_members (board_id, user_id, role) VALUES (?, ?, ?)
            ");
            $stmt->execute([$boardId, $memberId, $role]);

            // Log audit
            $stmt = $DB->prepare("
                INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
                VALUES (?, ?, 'board_member_added', ?, ?, NOW())
            ");
            $stmt->execute([$companyId, $userId, "Added user $memberId to board $boardId", $_SERVER['REMOTE_ADDR'] ?? '']);

            echo json_encode(['success' => true]);
            break;

        // ========== REMOVE BOARD MEMBER ==========
        case 'remove_board_member':
            $boardId = (int)($_POST['board_id'] ?? 0);
            $memberId = (int)($_POST['user_id'] ?? 0);

            if (!$boardId || !$memberId) {
                throw new Exception('Board ID and User ID required');
            }

            // Verify board belongs to this company
            $stmt = $DB->prepare("SELECT board_id FROM project_boards WHERE board_id = ? AND company_id = ?");
            $stmt->execute([$boardId, $companyId]);
            if (!$stmt->fetch()) {
                throw new Exception('Board not found');
            }

            $stmt = $DB->prepare("
                DELETE FROM board_members WHERE board_id = ? AND user_id = ?
            ");
            $stmt->execute([$boardId, $memberId]);

            // Log audit
            $stmt = $DB->prepare("
                INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
                VALUES (?, ?, 'board_member_removed', ?, ?, NOW())
            ");
            $stmt->execute([$companyId, $userId, "Removed user $memberId from board $boardId", $_SERVER['REMOTE_ADDR'] ?? '']);

            echo json_encode(['success' => true]);
            break;

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
                INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
                VALUES (?, ?, 'api_key_created', ?, ?, NOW())
            ");
            $stmt->execute([$companyId, $userId, "Created API key: $name", $_SERVER['REMOTE_ADDR'] ?? '']);

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
                INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
                VALUES (?, ?, 'api_key_revoked', ?, ?, NOW())
            ");
            $stmt->execute([$companyId, $userId, "Revoked API key ID: $keyId", $_SERVER['REMOTE_ADDR'] ?? '']);

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
                INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
                VALUES (?, ?, 'webhook_created', ?, ?, NOW())
            ");
            $stmt->execute([$companyId, $userId, "Created webhook: $url", $_SERVER['REMOTE_ADDR'] ?? '']);

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
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([$httpCode, $webhookId, $companyId]);

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
                INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
                VALUES (?, ?, 'webhook_deleted', ?, ?, NOW())
            ");
            $stmt->execute([$companyId, $userId, "Deleted webhook ID: $webhookId", $_SERVER['REMOTE_ADDR'] ?? '']);

            echo json_encode(['success' => true]);
            break;

        // ========== EXPORT AUDIT LOG ==========
        case 'export_audit':
            // Use same filters as audit.php
            $filterUser = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
            $filterAction = isset($_GET['action_filter']) ? trim($_GET['action_filter']) : null;
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

        // ========== SAVE SETTINGS ==========
        case 'save_settings':
            $settings = $_POST['settings'] ?? [];

            if (!is_array($settings) || empty($settings)) {
                throw new Exception('No settings provided');
            }

            foreach ($settings as $key => $value) {
                // Sanitize key to only allow alphanumeric and underscores
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                    continue;
                }
                setCRMSetting($key, $value);
            }

            // Log audit
            $settingKeys = implode(', ', array_keys($settings));
            $stmt = $DB->prepare("
                INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
                VALUES (?, ?, 'settings_updated', ?, ?, NOW())
            ");
            $stmt->execute([$companyId, $userId, "Updated settings: $settingKeys", $_SERVER['REMOTE_ADDR'] ?? '']);

            echo json_encode(['success' => true]);
            break;

        // ========== EXPORT COMPANY DATA ==========
        case 'export_data':
            $exportType = trim($_GET['type'] ?? 'all');

            $filename = "flowwork-export-{$exportType}-" . date('Y-m-d') . ".csv";
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            $output = fopen('php://output', 'w');

            switch ($exportType) {
                case 'users':
                    fputcsv($output, ['First Name', 'Last Name', 'Email', 'Role', 'Status', 'Seat', 'Created']);
                    $stmt = $DB->prepare("
                        SELECT u.first_name, u.last_name, u.email, uc.role,
                               u.status, u.is_seat, u.created_at
                        FROM user_companies uc
                        JOIN users u ON u.id = uc.user_id
                        WHERE uc.company_id = ?
                        ORDER BY u.created_at
                    ");
                    $stmt->execute([$companyId]);
                    while ($row = $stmt->fetch()) {
                        fputcsv($output, [
                            $row['first_name'], $row['last_name'], $row['email'],
                            $row['role'], $row['status'], $row['is_seat'] ? 'Yes' : 'No',
                            $row['created_at']
                        ]);
                    }
                    break;

                case 'projects':
                    fputcsv($output, ['Project Name', 'Created']);
                    $stmt = $DB->prepare("SELECT * FROM projects WHERE company_id = ? ORDER BY created_at");
                    $stmt->execute([$companyId]);
                    while ($row = $stmt->fetch()) {
                        fputcsv($output, [$row['name'], $row['created_at']]);
                    }
                    break;

                case 'audit':
                    fputcsv($output, ['Timestamp', 'User ID', 'Action', 'Details', 'IP']);
                    $stmt = $DB->prepare("SELECT * FROM audit_log WHERE company_id = ? ORDER BY timestamp DESC LIMIT 10000");
                    $stmt->execute([$companyId]);
                    while ($row = $stmt->fetch()) {
                        fputcsv($output, [
                            $row['timestamp'], $row['user_id'],
                            $row['action'], $row['details'], $row['ip']
                        ]);
                    }
                    break;

                default:
                    fputcsv($output, ['Type', 'Count']);
                    $stmt = $DB->prepare("SELECT COUNT(*) FROM user_companies WHERE company_id = ?");
                    $stmt->execute([$companyId]);
                    fputcsv($output, ['Users', $stmt->fetchColumn()]);
                    $stmt = $DB->prepare("SELECT COUNT(*) FROM projects WHERE company_id = ?");
                    $stmt->execute([$companyId]);
                    fputcsv($output, ['Projects', $stmt->fetchColumn()]);
                    $stmt = $DB->prepare("SELECT COUNT(*) FROM project_boards WHERE company_id = ?");
                    $stmt->execute([$companyId]);
                    fputcsv($output, ['Boards', $stmt->fetchColumn()]);
                    break;
            }

            fclose($output);
            exit;

        default:
            throw new Exception('Unknown action');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
