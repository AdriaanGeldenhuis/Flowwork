<?php
// /projects/api/file.delete.php
// Delete a file attachment from an item.  Only contributors (or higher) on
// the board may remove attachments.  This endpoint also removes the
// physical file from disk.  If the file does not exist on the
// filesystem, the record is still deleted.

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

// Attachment ID to delete
$fileId = (int)($_POST['file_id'] ?? 0);
if (!$fileId) respond_error('File ID required');

// Look up attachment and associated board item
$stmt = $DB->prepare(
    "SELECT a.item_id, a.file_path
     FROM board_item_attachments a
     JOIN board_items bi ON a.item_id = bi.id
     WHERE a.id = ? AND bi.company_id = ?"
);
$stmt->execute([$fileId, $COMPANY_ID]);
$attachment = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$attachment) respond_error('File not found', 404);

// Verify user has at least contributor rights on the board
$stmt = $DB->prepare(
    "SELECT board_id FROM board_items WHERE id = ? AND company_id = ?"
);
$stmt->execute([$attachment['item_id'], $COMPANY_ID]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$item) respond_error('Item not found', 404);

require_board_role($item['board_id'], 'contributor');

// Attempt to delete the physical file.  The stored file_path is
// relative to the web root (e.g. '/uploads/projects/xyz').  We
// construct an absolute path relative to this script.  If the file
// cannot be unlinked (e.g. missing), we log but continue.
$relPath = ltrim($attachment['file_path'] ?? '', '/');
if ($relPath) {
    $absPath = realpath(__DIR__ . '/../../' . $relPath) ?: (__DIR__ . '/../../' . $relPath);
    if (is_file($absPath)) {
        @unlink($absPath);
    }
}

// Delete the database record
try {
    $stmt = $DB->prepare("DELETE FROM board_item_attachments WHERE id = ?");
    $stmt->execute([$fileId]);
    respond_ok(['deleted' => true]);
} catch (Exception $e) {
    error_log('File delete error: ' . $e->getMessage());
    respond_error('Failed to delete file', 500);
}