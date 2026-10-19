<?php
// finances/lib/FinExport.php
//
// CSV downloads for every Finance list and report. Cells that a spreadsheet
// would read as a formula (=, +, -, @, tab, CR) are prefixed with an
// apostrophe — except plain numbers, which stay numbers. UTF-8 with a BOM so
// Excel shows names with accents correctly.

final class FinExport
{
    public static function cell($v): string
    {
        $s = (string)($v ?? '');
        if ($s === '') return '';
        if (preg_match('/^-?\d+(\.\d+)?$/', $s)) return $s;
        if (preg_match('/^[=+\-@\t\r]/', $s)) $s = "'" . $s;
        return $s;
    }

    /** Send a CSV and stop. */
    public static function csv(string $filename, array $head, array $rows): void
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename);
        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map([self::class, 'cell'], $head));
        foreach ($rows as $r) fputcsv($out, array_map([self::class, 'cell'], array_values($r)));
        fclose($out);
        exit;
    }
}
