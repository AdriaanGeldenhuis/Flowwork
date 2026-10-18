<?php
/**
 * The /export/pdf/* reports (AR/AP aging, payroll summary, project P&L, VAT
 * summary) wrote each line as `36 <y> Td` inside one text object. `Td` is a
 * RELATIVE move from the previous line, so the absolute y accumulated and
 * every row after the first landed off the page. They now share
 * includes/pdf/text_report_pdf.php, which places each line with an absolute
 * `1 0 0 1 x y Tm`, breaks onto new pages, and shrinks the font so the
 * widest (~100+ column) table row fits the page width.
 */
require __DIR__ . '/../lib/bootstrap.php';
require FLOWWORK_ROOT . '/includes/pdf/text_report_pdf.php';

/** Parse the generated PDF: per page, the font size and [x, y, text] lines. */
function parse_text_pdf(string $pdf): array
{
    preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $streams);
    $pages = [];
    foreach ($streams[1] as $content) {
        preg_match('/\/F1 ([\d.]+) Tf/', $content, $tf);
        preg_match_all('/1 0 0 1 ([\d.]+) ([\d.-]+) Tm \(((?:\\\\.|[^\\\\)])*)\) Tj/s', $content, $m, PREG_SET_ORDER);
        $pages[] = [
            'size'  => (float)$tf[1],
            'lines' => array_map(fn($l) => [(float)$l[1], (float)$l[2], preg_replace('/\\\\(.)/s', '$1', $l[3])], $m),
            'raw'   => $content,
        ];
    }
    return $pages;
}

/** Every xref entry points at its "N 0 obj" and /Count matches the page objects. */
function assert_pdf_structure(string $pdf, int $expectedPages, string $ctx): void
{
    preg_match('/startxref\n(\d+)\n%%EOF$/', $pdf, $sx);
    assert_true(isset($sx[1]) && substr($pdf, (int)$sx[1], 4) === 'xref', "$ctx: startxref points at the xref table");
    preg_match_all('/^(\d{10}) 00000 n $/m', $pdf, $offs);
    foreach ($offs[1] as $i => $off) {
        $num = $i + 1;
        assert_true(substr($pdf, (int)$off, strlen("$num 0 obj")) === "$num 0 obj", "$ctx: xref offset of object $num");
    }
    preg_match('/\/Type \/Pages \/Kids \[([^\]]*)\] \/Count (\d+)/', $pdf, $tree);
    assert_eq($expectedPages, (int)$tree[2], "$ctx: page tree /Count");
    assert_eq($expectedPages, preg_match_all('/\/Type \/Page /', $pdf), "$ctx: page objects");
}

// --- A short report (VAT summary shape) keeps the old 12pt / 14pt layout ---
$short = [
    'Concreto Civils - VAT Summary Report',
    'VAT No: 4123456789',
    'As of 2026-10-18',
    '',
    sprintf("%-25s %15s", 'Output VAT (Std Rate)', '150.00'),
    sprintf("%-25s %15s", 'Input VAT (Std Rate)', '45.00'),
    str_repeat('-', 45),
    sprintf("%-25s %15s", 'Net VAT Due', '105.00'),
];
$pdf = fw_text_report_pdf($short);
assert_pdf_structure($pdf, 1, 'short');
$pages = parse_text_pdf($pdf);
assert_eq(12.0, $pages[0]['size'], 'short report stays at 12pt');
assert_true(!preg_match('/\bTd\b/', $pages[0]['raw']), 'no relative Td positioning');
assert_eq($short, array_column($pages[0]['lines'], 2), 'every line rendered, in order, unchanged');
foreach ($pages[0]['lines'] as $i => [$x, $y]) {
    assert_eq(36.0, $x, "line $i at the left margin");
    assert_eq(812.0 - 14 * $i, $y, "line $i at its absolute baseline (812 - 14n)");
}

// --- A long, wide report (aging shape: 104 columns, 150 rows) ---
$long = ['Concreto Civils - Accounts Receivable Aging Report', 'As of 2026-10-18', ''];
for ($i = 1; $i <= 150; $i++) {
    $long[] = sprintf("%-30s %10s %10s %10s %10s %10s %12s", "Customer $i (Pty) Ltd \\ Branch", '1.00', '2.00', '3.00', '4.00', '5.00', '15.00');
}
$long[] = str_repeat('-', 104);
$pdf = fw_text_report_pdf($long);
$pages = parse_text_pdf($pdf);
assert_true(count($pages) >= 2, 'a long report breaks onto more than one page (' . count($pages) . ')');
assert_pdf_structure($pdf, count($pages), 'long');

$rendered = [];
foreach ($pages as $p => $page) {
    assert_true(!preg_match('/\bTd\b/', $page['raw']), "page $p: no relative Td positioning");
    assert_true($page['size'] >= 6.0 && $page['size'] < 12.0, "page $p: font shrunk for the wide table ({$page['size']}pt)");
    assert_eq(812.0, $page['lines'][0][1], "page $p starts at the top baseline");
    foreach ($page['lines'] as [$x, $y, $text]) {
        assert_true($y >= 36 && $y <= 812, "page $p: baseline $y within the page margins");
        $right = $x + strlen($text) * 0.6 * $page['size'];
        assert_true($right <= 595 - 36 + 0.001, "page $p: line ends at x=$right, inside the right margin");
        $rendered[] = $text;
    }
}
assert_eq($long, $rendered, 'all lines rendered once, in order, across pages; ( ) \\ escaped and round-trip');

// --- No lines still yields a valid one-page document ---
$pdf = fw_text_report_pdf([]);
assert_pdf_structure($pdf, 1, 'empty');

// --- Every PDF export uses the shared generator, none positions with Td ---
foreach (glob(FLOWWORK_ROOT . '/export/pdf/*.php') as $file) {
    $src = file_get_contents($file);
    $name = basename($file);
    assert_true(str_contains($src, "includes/pdf/text_report_pdf.php"), "$name includes the shared generator");
    assert_true(str_contains($src, 'fw_text_report_pdf($lines)'), "$name renders via fw_text_report_pdf()");
    assert_true(!preg_match('/\bTd\b/', $src), "$name has no hand-written Td positioning");
}

test_done('export_pdf_layout');
