<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$poId = (int)($_GET['po_id'] ?? 0);
$total = (float)($_GET['total'] ?? 0);

if (!$poId || !$total) {
    echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
    exit;
}

// Fetch GRNs for this PO (assuming you have 'goods_received_notes' table)
$stmt = $DB->prepare("
    SELECT 
        grn.id as grn_id,
        grn.grn_number,
        grn.total as grn_total,
        grn.received_date,
        ABS(grn.total - ?) / grn.total * 100 as variance_pct
    FROM goods_received_notes grn
    WHERE grn.company_id = ?
      AND grn.po_id = ?
      AND grn.status = 'completed'
    ORDER BY grn.received_date DESC
    LIMIT 10
");
$stmt->execute([$total, $companyId, $poId]);
$matches = $stmt->fetchAll();

// Calculate match strength
foreach ($matches as &$match) {
    $variancePct = $match['variance_pct'];
    
    if ($variancePct == 0) {
        $score = 100.00;
    } else {
        $score = max(0, 100 - $variancePct * 2); // Stricter for 3-way
    }
    
    $match['match_strength'] = round($score, 2);
    $match['variance_amount'] = round($match['grn_total'] - $total, 2);
}

echo json_encode([
    'ok' => true,
    'matches' => $matches
]);