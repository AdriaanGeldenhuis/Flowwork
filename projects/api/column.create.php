<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$boardId = (int)($_POST['board_id'] ?? 0);
$name    = trim($_POST['name'] ?? '');
$type    = $_POST['type'] ?? 'text';
$width   = (int)($_POST['width'] ?? 150);
if ($width < 30) $width = 30;
if ($width > 150) $width = 150;

// Optional config JSON string.  Frontend can pass a JSON string via the
// `config` field.  If absent, a sane default will be generated based on
// the column type below.  We decode here to ensure valid JSON, but
// re‑encode just before insertion.
$configRaw = isset($_POST['config']) ? trim($_POST['config']) : '';
// When decoded, this should be an array.  Leave null if decoding fails.
$configArr = null;
if ($configRaw !== '') {
    $tmp = json_decode($configRaw, true);
    if (is_array($tmp)) {
        $configArr = $tmp;
    }
}

if (!$boardId) respond_error('Board ID required');
if (!$name)    respond_error('Column name required');

// Must match DB enum
$allowedTypes = ['status','people','date','timeline','text','longtext','number','dropdown','checkbox','tags','link','email','phone','formula','progress'];
if (!in_array($type, $allowedTypes, true)) respond_error('Invalid column type');

require_board_role($boardId, 'manager');

// Determine a default config based on the column type if none was
// provided.  These defaults ensure new columns behave sensibly on
// creation.  For formula columns, an empty formula string and a
// precision of 2 decimal places are used.  For number columns, the
// precision is set to 2.  Status and dropdown columns receive a
// sensible set of labels/options, respectively.  All other column
// types leave config null.
if ($configArr === null) {
    switch ($type) {
        case 'status':
            // Default status labels with colors inspired by Tailwind
            $configArr = [
                'labels' => [
                    'To Do'        => '#64748b', // slate-500
                    'Working on it'=> '#fbbf24', // amber-400
                    'Done'         => '#22c55e', // green-500
                    'Stuck'        => '#ef4444'  // red-500
                ],
                // Default to sum aggregation for status is undefined
            ];
            break;
        case 'dropdown':
            $configArr = [
                'options' => ['Low', 'Medium', 'High', 'Urgent']
            ];
            break;
        case 'number':
            $configArr = [
                'precision' => 2,
                // Default aggregation is sum
                'agg' => 'sum'
            ];
            break;
        case 'formula':
            $configArr = [
                'formula'   => '',
                'precision' => 2,
                'agg'       => 'sum'
            ];
            break;
        default:
            // No default config for other types
            $configArr = null;
            break;
    }
}
// Finally, encode config to JSON or leave NULL.  We don't escape
// slashes to keep the stored JSON tidy.
$configJson = $configArr !== null ? json_encode($configArr, JSON_UNESCAPED_SLASHES) : null;

try {
    // Next position
    $stmt = $DB->prepare("SELECT COALESCE(MAX(position), 0) FROM board_columns WHERE board_id = ? AND company_id = ?");
    $stmt->execute([$boardId, $COMPANY_ID]);
    $maxPos = (int)$stmt->fetchColumn();

    $stmt = $DB->prepare("
        INSERT INTO board_columns (board_id, company_id, name, type, config, position, sort_order, visible, width, color, required, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 0, 1, ?, NULL, 0, NOW())
    ");
    // Note: $configJson may be null, which PDO will convert to NULL
    $stmt->execute([$boardId, $COMPANY_ID, $name, $type, $configJson, $maxPos + 1, $width]);

    $columnId = (int)$DB->lastInsertId();

    $stmt = $DB->prepare("
        INSERT INTO board_audit_log (company_id, board_id, user_id, action, details, ip_address, created_at)
        VALUES (?, ?, ?, 'column_added', ?, ?, NOW())
    ");
    $stmt->execute([
        $COMPANY_ID, $boardId, $USER_ID,
        json_encode(['column_id' => $columnId, 'name' => $name, 'type' => $type]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    respond_ok(['column_id' => $columnId]);

} catch (Exception $e) {
    error_log("Column create error: " . $e->getMessage());
    respond_error('Failed to create column', 500);
}
