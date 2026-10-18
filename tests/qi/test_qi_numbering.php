<?php
/**
 * Quotes & Invoices numbering (Settings → Numbering): prefixes, the year,
 * padding and separator; a continuous sequence when the year is left out;
 * numbers that already exist are skipped; "next number" can be set; peeking
 * never reserves a number.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/qi/lib/QiSchema.php';
require_once FLOWWORK_ROOT . '/qi/lib/QiWriter.php';
require_once FLOWWORK_ROOT . '/qi/lib/SequenceAllocator.php';

QiSchema::ensure($DB);
$C = TEST_COMPANY_ID;
$Y = date('Y');

// keep the company's real settings and put them back afterwards
$DB->prepare("INSERT IGNORE INTO qi_settings (company_id) VALUES (?)")->execute([$C]);
$saved = $DB->query("SELECT quote_prefix, invoice_prefix, credit_prefix, number_year, number_digits, number_sep FROM qi_settings WHERE company_id = $C")->fetch(PDO::FETCH_ASSOC);
$set = function (array $v) use ($DB, $C): void {
    $cols = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($v)));
    $DB->prepare("UPDATE qi_settings SET $cols WHERE company_id = ?")->execute(array_merge(array_values($v), [$C]));
};
$alloc = new SequenceAllocator($DB);

try {
    $set(['quote_prefix' => 'Q', 'invoice_prefix' => 'INV', 'credit_prefix' => 'CN', 'number_year' => 1, 'number_digits' => 4, 'number_sep' => '-']);
    assert_eq("Q$Y-0001", $alloc->peek($C, 'quote'), 'default quote format');
    assert_eq("Q$Y-0001", $alloc->peek($C, 'quote'), 'peeking does not reserve');
    [$code, $num] = $alloc->allocate($C, 'invoice', date('Y-m-d'));
    assert_eq(["INV$Y-0001", 1], [$code, $num], 'first invoice number');
    assert_eq("INV$Y-0002", $alloc->peek($C, 'invoice'), 'counter moved on');
    assert_eq('CN2025-0001', $alloc->peek($C, 'credit_note', '2025-06-30'), 'the year comes from the document date');

    // a sequence per year: last year's counter does not touch this year's
    $alloc->allocate($C, 'invoice', '2025-12-31');
    assert_eq("INV$Y-0002", $alloc->peek($C, 'invoice'), 'other years keep their own counter');

    // custom format, continuous (no year)
    $set(['quote_prefix' => 'QT', 'number_year' => 0, 'number_digits' => 5, 'number_sep' => '/']);
    assert_eq('QT/00001', $alloc->peek($C, 'quote'), 'custom prefix, padding and separator');
    assert_eq('QT/00042', SequenceAllocator::sample(['quote_prefix' => 'QT', 'number_year' => 0, 'number_digits' => 5, 'number_sep' => '/'], 'quote', 42), 'settings preview');

    // an existing number is skipped instead of colliding with the unique key
    $cust = make_customer($DB, 'Numbering');
    $DB->prepare("INSERT INTO quotes (company_id, quote_number, customer_id, issue_date, status, subtotal, tax, total, created_by, created_at)
                  VALUES (?, 'QT/00001', ?, CURDATE(), 'draft', 0, 0, 0, ?, NOW())")->execute([$C, $cust, TEST_USER_ID]);
    assert_eq('QT/00002', $alloc->peek($C, 'quote'), 'peek skips a number in use');
    [$code] = $alloc->allocate($C, 'quote');
    assert_eq('QT/00002', $code, 'allocation skips a number in use');

    // "next number" from Settings
    $alloc->setNext($C, 'quote', 120);
    assert_eq(['code' => 'QT/00120', 'num' => 120], $alloc->peekParts($C, 'quote'), 'next number set');
    $q = QiWriter::save($DB, $C, TEST_USER_ID, 'quote', ['customer_id' => $cust, 'issue_date' => date('Y-m-d'), 'rows' => [['kind' => 'item', 'description' => 'Work', 'qty' => 1, 'price' => 100, 'tax_code' => 'STD']]]);
    assert_eq('QT/00120', $q['number'], 'the editor uses the configured format');

    // no prefix and no year: just the padded number
    $set(['credit_prefix' => '', 'number_year' => 0, 'number_digits' => 3, 'number_sep' => '-']);
    assert_eq('001', $alloc->peek($C, 'credit_note'), 'bare number');

    // unsafe characters never reach a document number
    $set(['quote_prefix' => 'Q<b>"', 'number_year' => 0, 'number_digits' => 4, 'number_sep' => '%']);
    assert_eq('Qb-0121', $alloc->peek($C, 'quote'), 'prefix and separator are sanitised');
} finally {
    $set($saved);
}

test_done('qi_numbering');
