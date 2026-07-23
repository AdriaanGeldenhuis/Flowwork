<?php
// /qi/ajax/download_pdf.php — download a quote / invoice / credit note PDF.
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

if (!$id) {
    http_response_code(400);
    echo 'Invalid document ID';
    exit;
}
if (!in_array($type, ['invoice', 'quote', 'credit_note'], true)) {
    $type = 'invoice';
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
            throw new Exception('Invoice not found');
        }
        if ($inv['status'] === 'draft' && trim((string)($inv['vat_number'] ?? '')) !== '') {
            require_once __DIR__ . '/../../finances/lib/TaxInvoiceValidator.php';
            $validator = new TaxInvoiceValidator($DB, $companyId);
            $s20Errors = array_values(array_filter(
                $validator->validate((int)$id),
                static fn(array $i) => ($i['severity'] ?? '') === 'error'
            ));
            if ($s20Errors) {
                header('Content-Type: application/json');
                echo json_encode([
                    'ok' => false,
                    'error' => 'This draft is not a valid SARS tax invoice and cannot be downloaded. Fix the issues below, then try again.',
                    'issues' => $s20Errors,
                ]);
                exit;
            }
        }
    }

    $r = DocumentPdfService::generateAndFile($DB, $companyId, $type, (int)$id, $userId ?: null);
    if ($r === null || empty($r['bytes'])) {
        throw new Exception(ucfirst(str_replace('_', ' ', $type)) . ' not found');
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $r['filename'] . '"');
    header('Content-Length: ' . strlen($r['bytes']));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $r['bytes'];

} catch (Exception $e) {
    http_response_code(500);
    echo 'Error: ' . htmlspecialchars($e->getMessage());
}
