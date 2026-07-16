<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/_guard.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

// CSRF: the browser sends the token in the X-CSRF-Token header.
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$itemId = (int)($_POST['item_id'] ?? 0);
if (!$itemId) {
    echo json_encode(['ok' => false, 'error' => 'Missing item_id']);
    exit;
}

$USER_ID    = $_SESSION['user_id'];
$COMPANY_ID = $_SESSION['company_id'];
$USER_ROLE  = $_SESSION['role'] ?? 'viewer';

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

// Must be allowed to edit this board.
require_board_role((int)$item['board_id'], 'member');

// Only accept documents/images — never executable/script types that could be
// served back and run. Allowlist by extension.
$ALLOWED_EXT = [
    'pdf', 'txt', 'csv', 'rtf',
    'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'bmp', 'tiff',
    'zip',
];
$MAX_BYTES = 10 * 1024 * 1024; // 10MB per file (matches the UI copy)

// Build path: /workdrive.co.za/flowwork/{project}/{board}/{group}/
$baseDir = '/workdrive.co.za/flowwork';
$projectSlug = preg_replace('/[^a-z0-9_-]/i', '_', $item['project_name']);
$boardSlug   = preg_replace('/[^a-z0-9_-]/i', '_', $item['board_title']);
$groupSlug   = preg_replace('/[^a-z0-9_-]/i', '_', $item['group_name']);

$uploadDir = $baseDir . '/' . $projectSlug . '/' . $boardSlug . '/' . $groupSlug;

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$uploaded = [];
$rejected = [];

if (!empty($_FILES['files']) && is_array($_FILES['files']['tmp_name'])) {
    foreach ($_FILES['files']['tmp_name'] as $idx => $tmpName) {
        if ($_FILES['files']['error'][$idx] !== UPLOAD_ERR_OK) continue;

        $originalName = $_FILES['files']['name'][$idx];
        $fileSize     = (int)$_FILES['files']['size'][$idx];
        $mimeType     = $_FILES['files']['type'][$idx] ?? null;
        $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // Validate type + size before touching disk.
        if (!in_array($ext, $ALLOWED_EXT, true)) {
            $rejected[] = ['name' => $originalName, 'reason' => 'type not allowed'];
            continue;
        }
        if ($fileSize <= 0 || $fileSize > $MAX_BYTES) {
            $rejected[] = ['name' => $originalName, 'reason' => 'too large (max 10MB)'];
            continue;
        }

        $safeName = time() . '_' . uniqid() . '.' . $ext;
        $destPath = $uploadDir . '/' . $safeName;

        if (move_uploaded_file($tmpName, $destPath)) {
            $stmt = $DB->prepare("
                INSERT INTO board_item_attachments (item_id, file_name, file_path, file_size, mime_type, uploaded_by, uploaded_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$itemId, $originalName, $destPath, $fileSize, $mimeType, $USER_ID]);

            $uploaded[] = $originalName;
        }
    }
}

// Fresh total so the client can update the cell's "N files" pill.
$stmt = $DB->prepare("SELECT COUNT(*) FROM board_item_attachments WHERE item_id = ?");
$stmt->execute([$itemId]);
$total = (int)$stmt->fetchColumn();

echo json_encode(['ok' => true, 'uploaded' => $uploaded, 'rejected' => $rejected, 'total' => $total]);
