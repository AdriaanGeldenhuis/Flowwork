<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$itemId = (int)($_POST['item_id'] ?? 0);
$comment = trim($_POST['comment'] ?? '');

if (!$itemId || !$comment) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

$USER_ID = $_SESSION['user_id'];
$COMPANY_ID = $_SESSION['company_id'];

// Verify item access
$stmt = $DB->prepare("SELECT board_id FROM board_items WHERE id = ? AND company_id = ?");
$stmt->execute([$itemId, $COMPANY_ID]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied']);
    exit;
}

// Insert comment
$stmt = $DB->prepare("
    INSERT INTO board_item_comments 
    (item_id, user_id, comment, created_at)
    VALUES (?, ?, ?, NOW())
");
$stmt->execute([$itemId, $USER_ID, $comment]);

$commentId = $DB->lastInsertId();

// Log @mentions (TODO: email threads next sprint)
if (preg_match_all('/@(\w+)/', $comment, $matches)) {
    $mentions = array_unique($matches[1]);
    // Future: send email notifications to mentioned users
}

echo json_encode(['ok' => true, 'comment_id' => $commentId]);