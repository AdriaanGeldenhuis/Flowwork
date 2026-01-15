<?php
/**
 * PdfPath
 * Ensures a consistent PDF path is created and stored on the header row.
 * NOTE: This does NOT render a PDF. Keep using your existing PDF generator and write to $path.
 */
class PdfPath {
    public static function ensureQuotePdf(PDO $db, int $companyId, int $quoteId, string $quoteNumber, ?string $absoluteRoot = null): string {
        $path = self::buildPath($companyId, 'quote', $quoteNumber, $absoluteRoot);
        self::touch($path);
        $upd = $db->prepare("UPDATE quotes SET pdf_path=? WHERE id=?");
        $upd->execute([$path, $quoteId]);
        return $path;
    }

    public static function ensureInvoicePdf(PDO $db, int $companyId, int $invoiceId, string $invoiceNumber, ?string $absoluteRoot = null): string {
        $path = self::buildPath($companyId, 'invoice', $invoiceNumber, $absoluteRoot);
        self::touch($path);
        $upd = $db->prepare("UPDATE invoices SET pdf_path=? WHERE id=?");
        $upd->execute([$path, $invoiceId]);
        return $path;
    }

    private static function buildPath(int $companyId, string $type, string $code, ?string $root): string {
        $base = rtrim($root ?: dirname(__DIR__, 2), '/') . "/storage/qi/{$companyId}/{$type}";
        if (!is_dir($base)) { @mkdir($base, 0775, true); }
        return $base . "/{$code}.pdf";
    }

    private static function touch(string $path): void {
        if (!file_exists($path)) {
            @file_put_contents($path, "PDF placeholder for {$path}\n");
        }
    }
}
