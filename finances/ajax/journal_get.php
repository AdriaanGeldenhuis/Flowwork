<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ajax/journal_get.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']);

header('Content-Type: application/json');

$companyId = (int)($_SESSION['company_id'] ?? 0);
if (!$companyId) { json_error('Not authorised', 403); }
$journalId = isset($_GET['journal_id']) ? (int)$_GET['journal_id'] : 0;

if ($journalId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Journal ID required']);
    exit;
}

try {
    // Get journal header
    $stmt = $DB->prepare("
        SELECT
            je.id AS journal_id,
            je.entry_date,
            je.description AS memo,
            je.module,
            je.ref_type,
            je.ref_id,
            je.reference,
            je.status,
            je.created_at,
            je.reversed_by_journal_id,
            je.reverses_journal_id,
            je.approved_by,
            je.approved_at,
            je.posted_by,
            je.posted_at,
            u.first_name,
            u.last_name
        FROM journal_entries je
        LEFT JOIN users u ON je.created_by = u.id
        WHERE je.id = ? AND je.company_id = ?
    ");
    $stmt->execute([$journalId, $companyId]);
    $journal = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$journal) {
        throw new Exception('Journal not found');
    }

    // Get journal lines
    $stmt = $DB->prepare("
        SELECT
            jl.id AS line_id,
            jl.account_code,
            jl.description,
            jl.debit,
            jl.credit,
            jl.project_id,
            jl.board_id,
            jl.item_id,
            jl.tax_code_id,
            a.account_name
        FROM journal_lines jl
        LEFT JOIN gl_accounts a
          ON a.company_id = ? AND a.account_code = jl.account_code
        WHERE jl.journal_id = ?
        ORDER BY jl.id ASC
    ");
    $stmt->execute([$companyId, $journalId]);
    $journal['lines'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'data' => $journal
    ]);

} catch (Exception $e) {
    error_log("Journal get error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
