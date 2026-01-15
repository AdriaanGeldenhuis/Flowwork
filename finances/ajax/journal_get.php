<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ajax/journal_get.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$journalId = $_GET['journal_id'] ?? null;

if (!$journalId) {
    echo json_encode(['ok' => false, 'error' => 'Journal ID required']);
    exit;
}

try {
    // Get journal header
    $stmt = $DB->prepare("
        SELECT 
            je.journal_id,
            je.entry_date,
            je.memo,
            je.module,
            je.ref_type,
            je.ref_id,
            je.reference,
            je.created_at,
            u.first_name,
            u.last_name
        FROM journal_entries je
        LEFT JOIN users u ON je.created_by = u.id
        WHERE je.journal_id = ? AND je.company_id = ?
    ");
    $stmt->execute([$journalId, $companyId]);
    $journal = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$journal) {
        throw new Exception('Journal not found');
    }

    // Get journal lines with support for both legacy (account_id, debit_cents) and modern (account_code, debit) fields
    $stmt = $DB->prepare("
        SELECT
            jl.line_id,
            COALESCE(jl.account_code, a.account_code) AS account_code,
            jl.description,
            COALESCE(jl.debit, jl.debit_cents/100.0)  AS debit,
            COALESCE(jl.credit, jl.credit_cents/100.0) AS credit,
            jl.project_id,
            jl.board_id,
            jl.item_id,
            jl.tax_code_id,
            a.account_name
        FROM journal_lines jl
        JOIN gl_accounts a
          ON a.company_id = ? AND (a.account_code = jl.account_code OR a.account_id = jl.account_id)
        WHERE jl.journal_id = ?
        ORDER BY jl.line_id ASC
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