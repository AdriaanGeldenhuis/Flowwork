<?php
// /finances/ajax/recent_activity.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

try {
    // Get recent journal entries
    $stmt = $DB->prepare("
        SELECT
            je.id AS journal_id,
            je.entry_date,
            je.description AS memo,
            je.module,
            je.created_at,
            u.first_name,
            u.last_name,
            (SELECT COUNT(*) FROM journal_lines WHERE journal_id = je.id) as line_count,
            (SELECT SUM(debit) FROM journal_lines WHERE journal_id = je.id) as total_cents
        FROM journal_entries je
        LEFT JOIN users u ON je.created_by = u.id
        WHERE je.company_id = ?
        ORDER BY je.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$companyId]);
    $journals = $stmt->fetchAll();

    $activity = [];

    foreach ($journals as $j) {
        $icon = '📊';
        switch ($j['module']) {
            case 'ar': $icon = '📄'; break;
            case 'ap': $icon = '📑'; break;
            case 'bank': $icon = '🏦'; break;
            case 'payroll': $icon = '💰'; break;
            case 'pos': $icon = '🛒'; break;
        }

        $userName = trim($j['first_name'] . ' ' . $j['last_name']) ?: 'System';
        $amount = 'R ' . number_format($j['total_cents'] / 100, 2);
        
        $activity[] = [
            'icon' => $icon,
            'title' => $j['memo'] ?: 'Journal Entry',
            'meta' => "$userName · $amount · " . date('j M Y', strtotime($j['created_at']))
        ];
    }

    echo json_encode([
        'ok' => true,
        'data' => $activity
    ]);

} catch (Exception $e) {
    error_log("Recent activity error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to load recent activity'
    ]);
}