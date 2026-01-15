<?php
// Receipts widgets API: layout + data.
// Layout shape: [{ id, widget_key, size, config }]
// POST accepts partial updates: { slotId, widgetKey?, size?, config? }.
// Setting key: 'receipts_widgets_layout'

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

if (!isset($_SESSION['company_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']); exit;
}

$companyId = (int)$_SESSION['company_id'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$key       = 'receipts_widgets_layout';

function load_layout(PDO $DB, int $companyId, string $key): array {
    $stmt = $DB->prepare("SELECT id, setting_value
                          FROM company_settings
                          WHERE company_id = ? AND setting_key = ?
                          ORDER BY id DESC LIMIT 1");
    $stmt->execute([$companyId, $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['setting_value'])) {
        $decoded = json_decode($row['setting_value'], true);
        if (is_array($decoded)) {
            // Back-compat: upgrade older layout shape
            foreach ($decoded as &$s) {
                if (!is_array($s)) $s = ['id' => (int)$s, 'widget_key' => null];
                $s['id']         = (int)($s['id'] ?? 0);
                $s['widget_key'] = $s['widget_key'] ?? null;
                $s['size']       = in_array(($s['size'] ?? ''), ['sm','md','lg'], true) ? $s['size'] : 'md';
                $s['config']     = is_array($s['config'] ?? null) ? $s['config'] : [];
            }
            return $decoded;
        }
    }
    // default 6 empty slots
    $out = [];
    for ($i = 1; $i <= 6; $i++) $out[] = ['id'=>$i,'widget_key'=>null,'size'=>'md','config'=>[]];
    return $out;
}

if ($method === 'GET') {
    try {
        $layout = load_layout($DB, $companyId, $key);

        $data = [];

        // 1) Unreviewed uploads
        try {
            $stmt = $DB->prepare("
                SELECT COUNT(*) FROM receipt_file
                WHERE company_id = ? AND (ocr_status <> 'parsed' OR bill_id IS NULL)
            ");
            $stmt->execute([$companyId]);
            $data['unreviewed_uploads'] = (int)$stmt->fetchColumn();
        } catch (Exception $e) { $data['unreviewed_uploads'] = 0; }

        // 2) Bank matches pending (posted bills with unmatched journals)
        try {
            $stmt = $DB->prepare("
                SELECT COUNT(*) FROM ap_bills b
                WHERE b.company_id = ?
                  AND b.status = 'posted'
                  AND b.journal_id IS NOT NULL
                  AND b.journal_id NOT IN (
                        SELECT journal_id FROM gl_bank_transactions
                        WHERE company_id = ? AND matched = 1 AND journal_id IS NOT NULL
                  )
            ");
            $stmt->execute([$companyId, $companyId]);
            $data['bank_matches_pending'] = (int)$stmt->fetchColumn();
        } catch (Exception $e) { $data['bank_matches_pending'] = 0; }

        // 3) Top suppliers (90d)
        try {
            $since = date('Y-m-d', strtotime('-90 days'));
            $stmt = $DB->prepare("
                SELECT b.supplier_id,
                       COALESCE(ca.name, CONCAT('Supplier #', b.supplier_id)) AS name,
                       SUM(b.total) AS total
                FROM ap_bills b
                LEFT JOIN crm_accounts ca
                  ON ca.id = b.supplier_id AND ca.company_id = b.company_id
                WHERE b.company_id = ? AND b.status IN ('posted','paid')
                  AND b.issue_date >= ?
                GROUP BY b.supplier_id, ca.name
                ORDER BY total DESC
                LIMIT 5
            ");
            $stmt->execute([$companyId, $since]);
            $data['top_suppliers_90d'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) { $data['top_suppliers_90d'] = []; }

        // 4) This month spend (posted/paid by day)
        try {
            $start = date('Y-m-01'); $end = date('Y-m-t');
            $stmt = $DB->prepare("
                SELECT DATE(issue_date) AS date, SUM(total) AS total
                FROM ap_bills
                WHERE company_id = ? AND status IN ('posted','paid')
                  AND issue_date BETWEEN ? AND ?
                GROUP BY DATE(issue_date)
                ORDER BY DATE(issue_date)
            ");
            $stmt->execute([$companyId, $start, $end]);
            $data['this_month_spend'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) { $data['this_month_spend'] = []; }

        // 5) Cost by project (this month)
        try {
            $start = date('Y-m-01'); $end = date('Y-m-t');
            $stmt = $DB->prepare("
                SELECT p.name, SUM(bl.line_total) AS total
                FROM ap_bill_lines bl
                JOIN ap_bills b ON b.id = bl.bill_id AND b.company_id = bl.company_id
                LEFT JOIN boards p ON p.id = bl.project_board_id AND p.company_id = bl.company_id
                WHERE bl.company_id = ? AND b.status IN ('posted','paid')
                  AND b.issue_date BETWEEN ? AND ?
                GROUP BY p.name
                ORDER BY total DESC
                LIMIT 5
            ");
            $stmt->execute([$companyId, $start, $end]);
            $data['cost_by_project'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) { $data['cost_by_project'] = []; }

        // 6) Recent receipts
        try {
            $stmt = $DB->prepare("
                SELECT rf.file_id, rf.uploaded_at, rf.ocr_status,
                       ro.vendor_name, ro.invoice_number
                FROM receipt_file rf
                LEFT JOIN receipt_ocr ro ON ro.file_id = rf.file_id
                WHERE rf.company_id = ?
                ORDER BY rf.uploaded_at DESC
                LIMIT 5
            ");
            $stmt->execute([$companyId]);
            $data['recent_receipts'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) { $data['recent_receipts'] = []; }

        // 7) OCR confidence (scoped via receipt_file)
        try {
            $stmt = $DB->prepare("
                SELECT AVG(ro.confidence_score) AS avg_conf,
                       SUM(CASE WHEN ro.confidence_score < 0.80 THEN 1 ELSE 0 END) AS low_count
                FROM receipt_ocr ro
                JOIN receipt_file rf ON rf.file_id = ro.file_id
                WHERE rf.company_id = ?
            ");
            $stmt->execute([$companyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['avg_conf'=>0,'low_count'=>0];
            $data['ocr_confidence'] = [
                'avg_conf'  => $row['avg_conf'] !== null ? (float)$row['avg_conf'] : 0.0,
                'low_count' => (int)$row['low_count']
            ];
        } catch (Exception $e) { $data['ocr_confidence'] = ['avg_conf'=>0.0,'low_count'=>0]; }

        echo json_encode(['ok'=>true,'layout'=>$layout,'data'=>$data]); exit;
    } catch (Exception $e) {
        error_log('widgets GET error: '.$e->getMessage());
        echo json_encode(['ok'=>false,'error'=>'Server error']); exit;
    }
}

if ($method === 'POST') {
    try {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $slotId    = isset($in['slotId']) ? (int)$in['slotId'] : 0;
        $widgetKey = array_key_exists('widgetKey', $in) ? $in['widgetKey'] : '__NOCHANGE__';
        $size      = $in['size'] ?? null;
        $config    = $in['config'] ?? null;

        if ($slotId <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid slot']); exit; }

        $layout = load_layout($DB, $companyId, $key);
        $found = false;
        foreach ($layout as &$s) {
            if ((int)$s['id'] !== $slotId) continue;
            $found = true;
            if ($widgetKey !== '__NOCHANGE__') {
                // null = remove
                $s['widget_key'] = $widgetKey;
                if ($widgetKey === null) { $s['config'] = []; }
            }
            if ($size && in_array($size, ['sm','md','lg'], true)) $s['size'] = $size;
            if (is_array($config)) $s['config'] = $config;
            break;
        }
        if (!$found) {
            $layout[] = [
                'id' => $slotId,
                'widget_key' => ($widgetKey === '__NOCHANGE__') ? null : $widgetKey,
                'size' => in_array($size, ['sm','md','lg'], true) ? $size : 'md',
                'config' => is_array($config) ? $config : []
            ];
        }

        $val = json_encode($layout, JSON_UNESCAPED_UNICODE);

        $DB->beginTransaction();
        $del = $DB->prepare("DELETE FROM company_settings WHERE company_id = ? AND setting_key = ?");
        $del->execute([$companyId, $key]);
        $ins = $DB->prepare("INSERT INTO company_settings (company_id, setting_key, setting_value) VALUES (?,?,?)");
        $ins->execute([$companyId, $key, $val]);
        $DB->commit();

        echo json_encode(['ok'=>true]); exit;
    } catch (Exception $e) {
        if ($DB->inTransaction()) $DB->rollBack();
        error_log('widgets POST error: '.$e->getMessage());
        echo json_encode(['ok'=>false,'error'=>'Server error']); exit;
    }
}

echo json_encode(['ok'=>false,'error'=>'Unsupported method']);
