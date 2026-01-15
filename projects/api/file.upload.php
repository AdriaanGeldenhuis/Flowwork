<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

$itemId = (int)($_POST['item_id'] ?? 0);
if (!$itemId) {
    echo json_encode(['ok' => false, 'error' => 'Missing item_id']);
    exit;
}

$USER_ID = $_SESSION['user_id'];
$COMPANY_ID = $_SESSION['company_id'];

// Load item + board + project
$stmt = $DB->prepare("
    SELECT bi.*, pb.title as board_title, bg.name as group_name, p.name as project_name
    FROM board_items bi
    JOIN project_boards pb ON bi.board_id = pb.board_id
    JOIN board_groups bg ON bi.group_id = bg.id
    JOIN projects p ON pb.project_id = p.project_id
    WHERE bi.id = ? AND bi.company_id = ?
");
$stmt->execute([$itemId, $COMPANY_ID]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    echo json_encode(['ok' => false, 'error' => 'Item not found']);
    exit;
}

// Build path: /workdrive.co.za/flowwork/{project}/{board}/{group}/
$baseDir = '/workdrive.co.za/flowwork';
$projectSlug = preg_replace('/[^a-z0-9_-]/i', '_', $item['project_name']);
$boardSlug = preg_replace('/[^a-z0-9_-]/i', '_', $item['board_title']);
$groupSlug = preg_replace('/[^a-z0-9_-]/i', '_', $item['group_name']);

$uploadDir = $baseDir . '/' . $projectSlug . '/' . $boardSlug . '/' . $groupSlug;

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$uploaded = [];

if (!empty($_FILES['files'])) {
    foreach ($_FILES['files']['tmp_name'] as $idx => $tmpName) {
        if ($_FILES['files']['error'][$idx] !== UPLOAD_ERR_OK) continue;
        
        $originalName = $_FILES['files']['name'][$idx];
        $fileSize = $_FILES['files']['size'][$idx];
        $ext = pathinfo($originalName, PATHINFO_EXTENSION);
        $safeName = time() . '_' . uniqid() . '.' . $ext;
        $destPath = $uploadDir . '/' . $safeName;
        
        if (move_uploaded_file($tmpName, $destPath)) {
            $stmt = $DB->prepare("
                INSERT INTO board_item_attachments (item_id, file_name, file_path, file_size, uploaded_by, uploaded_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$itemId, $originalName, $destPath, $fileSize, $USER_ID]);
            
            $uploaded[] = $originalName;
        }
    }
}

echo json_encode(['ok' => true, 'uploaded' => $uploaded]);