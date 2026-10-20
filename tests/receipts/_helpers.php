<?php
/**
 * Receipts test helpers: a real (tiny) PDF with a text layer, a slip stored
 * the way an upload is, and a reading put in place without a reader.
 */
require_once FLOWWORK_ROOT . '/receipts/lib/Rc.php';

/** A one-page PDF whose text layer holds $lines (pdftotext reads it back). */
function rc_pdf(string $path, array $lines): string
{
    $content = "BT /F1 11 Tf 40 800 Td 14 TL\n";
    foreach ($lines as $l) $content .= '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $l) . ") Tj T*\n";
    $content .= 'ET';
    $objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
             '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
             '<< /Length ' . strlen($content) . " >>\nstream\n$content\nendstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    $out = "%PDF-1.4\n";
    $off = [];
    foreach ($objs as $i => $o) { $off[] = strlen($out); $out .= ($i + 1) . " 0 obj\n$o\nendobj\n"; }
    $x = strlen($out);
    $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($off as $o) $out .= sprintf("%010d 00000 n \n", $o);
    $out .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$x\n%%EOF";
    file_put_contents($path, $out);
    return $path;
}

/** Store a slip as an upload would; returns its file_id. */
function rc_slip(PDO $db, array $lines, int $userId = TEST_USER_ID, string $note = ''): int
{
    $p = rc_pdf(tempnam(sys_get_temp_dir(), 'rcslip') . '.pdf', array_merge($lines, ['ref ' . bin2hex(random_bytes(6))]));
    $r = RcFiles::store($db, TEST_COMPANY_ID, $userId, $p, 'slip.pdf', 15 * 1048576, $note);
    @unlink($p);
    return $r['file_id'];
}

/** Put a reading in place (as a reader would) without needing one on this machine. */
function rc_reading(PDO $db, int $fileId, array $f): void
{
    $f += ['vendor_name' => null, 'vendor_vat' => null, 'invoice_number' => null, 'invoice_date' => null, 'due_date' => null, 'currency' => 'ZAR',
           'subtotal' => null, 'tax' => null, 'total' => null, 'prices_include_vat' => null, 'lines' => [], 'item_names' => [], 'confidence' => 0.85];
    $db->prepare("DELETE FROM receipt_ocr WHERE file_id = ?")->execute([$fileId]);
    $db->prepare("INSERT INTO receipt_ocr (file_id, raw_json, vendor_name, vendor_vat, invoice_number, invoice_date, currency, subtotal, tax, total, line_items_json, confidence_score, created_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
       ->execute([$fileId, json_encode(['engine' => 'test', 'fields' => $f]), $f['vendor_name'], $f['vendor_vat'], $f['invoice_number'], $f['invoice_date'], $f['currency'],
                  $f['subtotal'], $f['tax'], $f['total'], json_encode($f['lines']), $f['confidence']]);
    $db->prepare("UPDATE receipt_file SET ocr_status = 'parsed', read_engine = 'test', read_at = NOW() WHERE file_id = ?")->execute([$fileId]);
}

/** Forget the schema marker so RcSchema::ensure runs in this test database. */
function rc_fresh_schema(PDO $db): void
{
    foreach (glob(FLOWWORK_ROOT . '/storage/receipts/.schema-*') ?: [] as $m) @unlink($m);
    RcSchema::ensure($db);
}
