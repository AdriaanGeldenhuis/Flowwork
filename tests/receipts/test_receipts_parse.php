<?php
/**
 * Receipts: RcParse turns a slip's text into fields. South African slips —
 * day-first dates, "R 1 234,50" and "1,234.50", VAT numbers, "Incl. VAT",
 * "Amount due" — and nothing made up: an empty or unreadable text gives
 * empty fields, VAT is never assumed without a sign of it, and line items
 * only come back when they add up to the total (incl. VAT) or the subtotal.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/receipts/lib/Rc.php';

$T = '2026-10-20';

// ---------------------------------------------------------------- money and dates
foreach (['1 234,50' => 1234.50, 'R1,234.50' => 1234.50, '1234.5' => 1234.50, '1.234,50' => 1234.50, '89.90' => 89.90, 'R 0,99' => 0.99, 'abc' => null, '' => null] as $in => $want) {
    assert_eq($want, RcParse::money((string)$in), "money '$in'");
}
foreach ([
    '14/10/2026' => '2026-10-14', '2026-10-14' => '2026-10-14', '14.10.2026' => '2026-10-14', '14-10-26' => '2026-10-14',
    '12 Oct 2026' => '2026-10-12', '12 October 2026' => '2026-10-12', 'Oct 12, 2026' => '2026-10-12', '5 Mei 2026' => '2026-05-05',
    '10/25/2026' => '2026-10-25',      // only read month-first when it cannot be day-first
    '31/02/2026' => null,              // no such day
    '14/10/2001' => null,              // a receipt is not from the far past
    '14/10/2031' => null,              // nor from the future
] as $in => $want) {
    assert_eq($want, RcParse::date($in, $T), "date '$in'");
}

// ---------------------------------------------------------------- a hardware till slip
$f = RcParse::fields("BUILDERS WAREHOUSE\nRandburg\nVAT No: 4123456789\nTel 011 555 1234\nTAX INVOICE\nInvoice No: BW-204551\nDate: 14/10/2026 13:22\n\n"
    . "Cement 50kg  3  89.90  269.70\nRiver sand bag     45.00\n2 x Gloves  35.00  70.00\n\nTOTAL  384.70\nVAT 15%  50.18\nCARD  384.70\nChange 0.00\n", $T);
assert_eq('Builders Warehouse', $f['vendor_name'], 'vendor from the first line, in title case');
assert_eq('4123456789', $f['vendor_vat'], 'VAT number');
assert_eq('BW-204551', $f['invoice_number'], 'invoice number');
assert_eq('2026-10-14', $f['invoice_date'], 'date, day first');
assert_cents(384.70, $f['total'], 'total');
assert_cents(50.18, $f['tax'], 'VAT');
assert_cents(334.52, $f['subtotal'], 'subtotal = total − VAT');
assert_eq(3, count($f['lines']), 'three item lines (tender and change left out)');
assert_eq(['Cement 50kg', 3.0, 89.90], [$f['lines'][0]['description'], (float)$f['lines'][0]['qty'], (float)$f['lines'][0]['unit_price']], 'qty × price line');
assert_eq(['Gloves', 2.0, 35.0], [$f['lines'][2]['description'], (float)$f['lines'][2]['qty'], (float)$f['lines'][2]['unit_price']], '"2 x Gloves 35.00 70.00"');
assert_true($f['prices_include_vat'] === true, 'lines add up to the total: prices include VAT');

// ---------------------------------------------------------------- a fuel slip: VAT inclusive, no lines that add up
$f = RcParse::fields("Engen Fourways\nTax Invoice\nSlip 004512\n12 Oct 2026\nUnleaded 95  42.10 L\nTotal incl VAT  1 050,00\nVAT Reg No 4987654321\n", $T);
assert_eq('Engen Fourways', $f['vendor_name'], 'vendor');
assert_eq('004512', $f['invoice_number'], 'slip number');
assert_eq('4987654321', $f['vendor_vat'], 'VAT Reg No');
assert_cents(1050.00, $f['total'], '"1 050,00"');
assert_cents(136.96, $f['tax'], 'VAT worked out of an inclusive total (15/115)');
assert_eq([], $f['lines'], 'items that do not add up are not kept');

// ---------------------------------------------------------------- a supplier invoice: excl. VAT, due date
$f = RcParse::fields("ACME Consulting (Pty) Ltd\nInvoice # INV-2026-118\nInvoice date 2026-10-01\nDue date 2026-10-31\nConsulting services   10 000.00\nSubtotal  10 000.00\nVAT 15%  1 500.00\nAmount Due  11 500.00\n", $T);
assert_eq('INV-2026-118', $f['invoice_number'], 'invoice #');
assert_eq('2026-10-01', $f['invoice_date'], 'the invoice date, not the due date');
assert_eq('2026-10-31', $f['due_date'], 'due date');
assert_cents(11500, $f['total'], '"Amount Due" is the total');
assert_cents(10000, $f['subtotal'], 'subtotal');
assert_cents(1500, $f['tax'], 'VAT');
assert_true($f['prices_include_vat'] === false && count($f['lines']) === 1, 'the line adds up to the subtotal: prices exclude VAT');

// ---------------------------------------------------------------- nothing made up
$f = RcParse::fields('', $T);
foreach (['vendor_name', 'vendor_vat', 'invoice_number', 'invoice_date', 'total', 'tax', 'subtotal'] as $k) assert_true($f[$k] === null, "empty text: $k stays empty");
assert_eq(0.0, (float)$f['confidence'], 'empty text: no confidence');
$f = RcParse::fields("Corner Cafe\nThank you\nTOTAL 54.00\n", $T);
assert_cents(54, $f['total'], 'total found');
assert_true($f['tax'] === null, 'no VAT is assumed without a VAT number or "incl. VAT" on the slip');
assert_eq('ZAR', $f['currency'], 'rand by default');
$f = RcParse::fields("Amazon Web Services\nInvoice 99812\nTotal USD 12.40\n", $T);
assert_eq('USD', $f['currency'], 'another currency when printed');

// ---------------------------------------------------------------- the AI reader's answer → fields (no network)
$fromAi = new ReflectionMethod(RcReader::class, 'fromAi');
$fromAi->setAccessible(true);
$ai = ['is_bill' => true, 'supplier_name' => ' Builders Warehouse ', 'supplier_vat_number' => '4123 456 789', 'document_number' => 'BW-1', 'document_date' => '14/10/2026',
       'due_date' => '2026-10-01', 'currency' => 'R', 'subtotal_excl_vat' => null, 'vat_amount' => 50.18, 'total' => 384.70, 'prices_include_vat' => null,
       'lines' => [['description' => 'Cement 50kg', 'quantity' => 3, 'unit_price' => 89.90, 'amount' => 269.70], ['description' => 'Sand', 'quantity' => null, 'unit_price' => null, 'amount' => 45.00],
                   ['description' => 'Gloves', 'quantity' => 2, 'unit_price' => 35.00, 'amount' => null], ['description' => '', 'quantity' => 1, 'unit_price' => 1, 'amount' => 1]],
       'account_code' => '6900'];
$f = $fromAi->invoke(null, $ai);
assert_eq(['Builders Warehouse', '4123456789', 'BW-1', '2026-10-14', 'ZAR'], [$f['vendor_name'], $f['vendor_vat'], $f['invoice_number'], $f['invoice_date'], $f['currency']], 'AI: trimmed, VAT digits, a day-first date, R = ZAR');
assert_true($f['due_date'] === null, 'AI: a due date before the invoice date is dropped');
assert_cents(334.52, $f['subtotal'], 'AI: subtotal from total − VAT');
assert_eq(3, count($f['lines']), 'AI: blank lines dropped; missing amounts worked out');
assert_cents(70, $f['lines'][2]['amount'], 'AI: amount = qty × price');
assert_true($f['prices_include_vat'] === true, 'AI: lines that add up to the total include VAT');
$f = $fromAi->invoke(null, ['supplier_vat_number' => '123', 'lines' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => 10, 'amount' => 10]], 'total' => 99.0] + $ai);
assert_true($f['vendor_vat'] === null, 'AI: a VAT number that is not 10 digits starting with 4 is dropped');
assert_eq([[], ['Item']], [$f['lines'], $f['item_names']], 'AI: lines that do not add up are not kept (their names describe the one line)');

test_done('receipts_parse');
