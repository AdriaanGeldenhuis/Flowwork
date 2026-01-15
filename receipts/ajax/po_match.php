<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$supplierId = (int)($_GET['supplier_id'] ?? 0);
$total = (float)($_GET['total'] ?? 0);

if (!$supplierId || !$total) {
    echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
    exit;
}

// Fetch settings
$stmt = $DB->prepare("SELECT variance_tolerance_pct FROM receipt_settings WHERE company_id = ?");
$stmt->execute([$companyId]);
$settings = $stmt->fetch();
$tolerance = $settings['variance_tolerance_pct'] ?? 5.00;

// Find matching POs (assuming you have a 'purchase_orders' table)
// For demo, we'll simulate with a simple query
$stmt = $DB->prepare("
    SELECT 
        po.id as po_id,
        po.po_number,
        po.total as po_total,
        po.status,
        po.created_at,
        ABS(po.total - ?) / po.total * 100 as variance_pct
    FROM purchase_orders po
    WHERE po.company_id = ?
      AND po.supplier_id = ?
      AND po.status IN ('approved', 'partial')
      AND ABS(po.total - ?) / po.total * 100 <= ?
    ORDER BY variance_pct ASC
    LIMIT 10
");
$stmt->execute([$total, $companyId, $supplierId, $total, $tolerance]);
$matches = $stmt->fetchAll();

// Calculate match strength
foreach ($matches as &$match) {
    $variancePct = $match['variance_pct'];
    
    // Scoring: 100 = perfect match, 0 = at tolerance edge
    if ($variancePct == 0) {
        $score = 100.00;
    } else {
        $score = max(0, 100 - ($variancePct / $tolerance * 100));
    }
    
    $match['match_strength'] = round($score, 2);
    $match['variance_amount'] = round($match['po_total'] - $total, 2);
}

echo json_encode([
    'ok' => true,
    'matches' => $matches,
    'tolerance_pct' => $tolerance
]);