<?php
// qi/lib/require_writer.php
//
// DUE-DILIGENCE-2026-07 B19: block the read-only "viewer" role from mutating
// financial data (payments, credit notes, invoice status changes, deletes).
// auth_gate.php refreshes $_SESSION['role'] per request, so this include must
// come AFTER it. Emits 403 JSON (auth_gate + qi.ui.js now signal XHR, so the
// client shows a clear permission message instead of a "Network error").
//
// NOTE: FINANCE-SARS-FINDINGS.md records that tightening these endpoints all the
// way to admin/bookkeeper-only (as the AP/bank endpoints are) is a product
// decision about who may capture payments — that is intentionally left to the
// team. This guard only closes the clear gap: read-only means read-only.
if (strtolower((string)($_SESSION['role'] ?? 'viewer')) === 'viewer') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'You do not have permission to perform this action.']);
    exit;
}
