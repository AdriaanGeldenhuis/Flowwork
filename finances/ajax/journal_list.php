<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ajax/journal_list.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

// Filters
$dateFrom = $_GET['dateFrom'] ?? '';
$dateTo = $_GET['dateTo'] ?? '';
$module = $_GET['module'] ?? '';
$search = $_GET['search'] ?? '';

try {
    $sql = "
        SELECT 
            je.journal_id,
            je.entry_date,
            je.memo,
            je.module,
            je.ref_type,
            je.ref_id,
            je.created_at,
            (SELECT COUNT(*) FROM journal_lines WHERE journal_id = je.journal_id) as line_count,
            (SELECT SUM(debit_cents) FROM journal_lines WHERE journal_id = je.journal_id) as total_debits
        FROM journal_entries je
        WHERE je.company_id = ?
    ";

    $params = [$companyId];

    if ($dateFrom) {
        $sql .= " AND je.entry_date >= ?";
        $params[] = $dateFrom;
    }

    if ($dateTo) {
        $sql .= " AND je.entry_date <= ?";
        $params[] = $dateTo;
    }

    if ($module) {
        $sql .= " AND je.module = ?";
        $params[] = $module;
    }

    if ($search) {
        $sql .= " AND (je.memo LIKE ? OR je.reference LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $sql .= " ORDER BY je.entry_date DESC, je.journal_id DESC LIMIT 100";

    $stmt = $DB->prepare($sql);
    $stmt->execute($params);
    $journals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'data' => $journals
    ]);

} catch (Exception $e) {
    error_log("Journal list error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to load journal entries'
    ]);
}