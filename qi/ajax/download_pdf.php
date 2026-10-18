<?php
// /qi/ajax/download_pdf.php — download (or, with inline=1, open/print) a quote,
// invoice or credit note PDF.
//
// Delegates to DocumentPdfService so the downloaded document is byte-identical
// to the emailed one and the copy in FlowWork Drive (single render recipe).
// Rendering also refreshes storage/qi/... and the drive copy as a side effect.
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../services/DocumentPdfService.php';

$companyId = (int)$_SESSION['company_id'];
$userId    = (int)($_SESSION['user_id'] ?? 0);
$type      = $_GET['type'] ?? 'invoice';
$id        = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$inline    = ($_GET['inline'] ?? '') === '1';
$wantsJson = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;

if (!$id) {
    http_response_code(400);
    echo 'Invalid document ID';
    exit;
}
if (!in_array($type, ['invoice', 'quote', 'credit_note'], true)) {
    $type = 'invoice';
}

/** Explain why no PDF was produced — JSON for fetch() callers, a small page for people. */
function qi_pdf_refuse(int $code, string $message, array $issues, bool $wantsJson): void
{
    http_response_code($code);
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $message, 'issues' => $issues]);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    $items = '';
    foreach ($issues as $i) {
        $items .= '<li>' . htmlspecialchars((string)($i['message'] ?? $i['field'] ?? 'Issue')) . '</li>';
    }
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>PDF not available</title><body style="font:15px/1.5 system-ui,sans-serif;max-width:560px;margin:12vh auto;padding:0 20px;color:#2a1d18">'
        . '<h1 style="font-weight:500;font-size:24px">PDF not available</h1><p>' . htmlspecialchars($message) . '</p>'
        . ($items !== '' ? '<ul>' . $items . '</ul>' : '')
        . '<p><a href="javascript:history.back()">Go back</a></p></body>';
    exit;
}

try {
    if ($type === 'invoice') {
        // SARS s20 gate: never produce a PDF of a DRAFT that fails the
        // tax-invoice checks for a VAT-registered company. Issued invoices
        // always render — history must stay accessible.
        $stmt = $DB->prepare(
            "SELECT i.status, c.vat_number
               FROM invoices i LEFT JOIN companies c ON i.company_id = c.id
              WHERE i.id = ? AND i.company_id = ?"
        );
        $stmt->execute([$id, $companyId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            qi_pdf_refuse(404, 'Invoice not found.', [], $wantsJson);
        }
        if ($inv['status'] === 'draft' && trim((string)($inv['vat_number'] ?? '')) !== '') {
            require_once __DIR__ . '/../../finances/lib/TaxInvoiceValidator.php';
            $validator = new TaxInvoiceValidator($DB, $companyId);
            $s20Errors = array_values(array_filter(
                $validator->validate((int)$id),
                static fn(array $i) => ($i['severity'] ?? '') === 'error'
            ));
            if ($s20Errors) {
                qi_pdf_refuse(422, 'This draft is not a valid SARS tax invoice yet, so no PDF was made. Fix the issues below, then try again.', $s20Errors, $wantsJson);
            }
        }
    }

    $r = DocumentPdfService::generateAndFile($DB, $companyId, $type, (int)$id, $userId ?: null);
    if ($r === null || empty($r['bytes'])) {
        qi_pdf_refuse(404, ucfirst(str_replace('_', ' ', $type)) . ' not found.', [], $wantsJson);
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $r['filename'] . '"');
    header('Content-Length: ' . strlen($r['bytes']));
    header('Cache-Control: private, no-cache, no-store, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    echo $r['bytes'];

} catch (Throwable $e) {
    error_log('[qi/download_pdf] ' . $e->getMessage());
    qi_pdf_refuse(500, 'The PDF could not be produced. Please try again.', [], $wantsJson);
}
