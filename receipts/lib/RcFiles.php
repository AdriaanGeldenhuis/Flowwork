<?php
// /receipts/lib/RcFiles.php — the slips themselves.
//
// A file is judged by its content (finfo + a real decode of images, the %PDF
// header for PDFs), never its name. Each one gets a random name under
// storage/receipts/<company>/<yyyy>/<mm>/ — closed to the web — and is only
// handed out by api/file.php after the company and person checks. Receipts
// from before the rebuild stay where they were (uploads/receipts/<company>/…)
// and are served the same way. The same file sent in twice is caught by its
// SHA-1. Photos get a small WebP (or JPEG) preview for the lists, turned the
// right way up from the phone's EXIF orientation.

final class RcFiles
{
    /** mime => extension */
    public const TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    public const HARD_MAX = 25 * 1024 * 1024;
    private const ROOTS = ['storage/receipts/', 'uploads/receipts/'];

    /**
     * Keep a file that arrived ($tmp is an upload or, from a ZIP, a temp file we wrote).
     * @return array{file_id:int, duplicate:bool, name:string}
     */
    public static function store(PDO $db, int $companyId, int $userId, string $tmp, string $originalName, int $maxBytes, string $note = ''): array
    {
        $size = is_file($tmp) ? (int)filesize($tmp) : 0;
        $name = self::cleanName($originalName);
        if ($size <= 0) throw new RcProblem("“{$name}” is empty.", 422);
        if ($size > min($maxBytes, self::HARD_MAX)) throw new RcProblem("“{$name}” is too large — " . round(min($maxBytes, self::HARD_MAX) / 1048576) . ' MB at most.', 413);
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if ($mime === 'image/jpg') $mime = 'image/jpeg';
        $ext = self::TYPES[$mime] ?? null;
        if (!$ext) throw new RcProblem("“{$name}” is not a PDF or a photo (JPG, PNG or WebP).", 415);
        if ($ext === 'pdf') {
            $head = (string)file_get_contents($tmp, false, null, 0, 1024);
            if (!str_contains($head, '%PDF-')) throw new RcProblem("“{$name}” is not a readable PDF.", 415);
        } elseif (!@getimagesize($tmp)) {
            throw new RcProblem("“{$name}” is not a readable picture.", 415);
        }
        $sha1 = sha1_file($tmp);
        $st = $db->prepare("SELECT file_id FROM receipt_file WHERE company_id = ? AND sha1 = ? ORDER BY file_id LIMIT 1");
        $st->execute([$companyId, $sha1]);
        if ($dup = (int)$st->fetchColumn()) return ['file_id' => $dup, 'duplicate' => true, 'name' => $name];

        $root = RcKit::storageDir();
        if ($root === null) throw new RcProblem('Receipts cannot be stored right now. Please tell your administrator.', 500);
        $rel = $companyId . '/' . date('Y') . '/' . date('m');
        $dir = $root . '/' . $rel;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) throw new RcProblem('Receipts cannot be stored right now. Please tell your administrator.', 500);
        $base = bin2hex(random_bytes(16));
        $dest = $dir . '/' . $base . '.' . $ext;
        $moved = is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $dest) : @copy($tmp, $dest);
        if (!$moved) throw new RcProblem("“{$name}” could not be saved. Please try again.", 500);
        @chmod($dest, 0640);
        $thumb = $ext === 'pdf' ? self::pdfThumb($dest, $dir . '/' . $base) : self::imageThumb($dest, $mime, $dir . '/' . $base);
        try {
            $db->prepare("INSERT INTO receipt_file (company_id, path, original_name, mime, size_bytes, sha1, uploaded_by, uploaded_at, ocr_status, note, thumb_path)
                          VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'pending', ?, ?)")
               ->execute([$companyId, 'storage/receipts/' . $rel . '/' . $base . '.' . $ext, $name, $mime, $size, $sha1, $userId,
                          ($n = mb_substr(trim($note), 0, 500)) !== '' ? $n : null, $thumb ? 'storage/receipts/' . $rel . '/' . basename($thumb) : null]);
        } catch (Throwable $e) {
            @unlink($dest);
            if ($thumb) @unlink($thumb);
            throw $e;
        }
        $id = (int)$db->lastInsertId();
        RcKit::audit($db, $companyId, $userId, 'receipt_uploaded', $id, ['name' => $name, 'size' => $size, 'sha1' => $sha1]);
        return ['file_id' => $id, 'duplicate' => false, 'name' => $name];
    }

    /** The file on disk for a receipt row, or null when it is missing or outside the receipts folders. */
    public static function path(int $companyId, ?string $rel): ?string
    {
        $rel = ltrim(str_replace('\\', '/', (string)$rel), '/');
        if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0")) return null;
        $ok = false;
        foreach (self::ROOTS as $root) {
            if (str_starts_with($rel, $root . $companyId . '/')) { $ok = true; break; }
        }
        if (!$ok) return null;
        $full = realpath(dirname(__DIR__, 2) . '/' . $rel);
        if ($full === false || !is_file($full)) return null;
        foreach (self::ROOTS as $root) {
            $base = realpath(dirname(__DIR__, 2) . '/' . rtrim($root, '/'));
            if ($base !== false && str_starts_with($full, $base . DIRECTORY_SEPARATOR . $companyId . DIRECTORY_SEPARATOR)) return $full;
        }
        return null;
    }

    /** Stream a receipt (or its preview) to the browser. */
    public static function send(array $row, bool $thumb, bool $download): void
    {
        $companyId = (int)$row['company_id'];
        $path = null;
        $mime = (string)$row['mime'];
        if ($thumb && !empty($row['thumb_path'])) {
            $path = self::path($companyId, $row['thumb_path']);
            if ($path) $mime = str_ends_with($path, '.webp') ? 'image/webp' : 'image/jpeg';
        }
        if (!$path) {
            if ($thumb && !str_starts_with($mime, 'image/')) throw new RcProblem('No preview.', 404);
            $path = self::path($companyId, $row['path']);
        }
        if (!$path) throw new RcProblem('That file is no longer on the server.', 404);
        if ($mime === 'image/jpg') $mime = 'image/jpeg';
        if (!isset(self::TYPES[$mime]) && !in_array($mime, ['image/webp', 'image/jpeg'], true)) $mime = 'application/octet-stream';
        $etag = '"' . substr(sha1($path . '|' . filemtime($path) . '|' . filesize($path)), 0, 24) . '"';
        header('Cache-Control: private, max-age=86400');
        header('ETag: ' . $etag);
        header('X-Content-Type-Options: nosniff');
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            exit;
        }
        $name = self::cleanName((string)($row['original_name'] ?: ('receipt-' . $row['file_id'] . '.' . (self::TYPES[$mime] ?? 'bin'))));
        if (str_starts_with($mime, 'image/')) header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . str_replace('"', '', preg_replace('/[^\x20-\x7e]/', '_', $name)) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        readfile($path);
        exit;
    }

    /** Remove a receipt's files from disk (the row is the caller's). */
    public static function unlinkFiles(array $row): void
    {
        $companyId = (int)$row['company_id'];
        foreach ([$row['path'] ?? null, $row['thumb_path'] ?? null] as $rel) {
            $p = self::path($companyId, $rel);
            if ($p) @unlink($p);
        }
    }

    public static function cleanName(string $n): string
    {
        $n = basename(str_replace('\\', '/', $n));
        $n = preg_replace('/[\x00-\x1f\x7f"<>:|?*\/\\\\]+/u', '', $n);
        $n = trim((string)$n) ?: 'receipt';
        return mb_substr($n, 0, 200);
    }

    // -------------------------------------------------------------- previews

    /** A 360px-wide preview of a photo, upright. @return string|null the file written */
    private static function imageThumb(string $src, string $mime, string $destBase): ?string
    {
        if (!function_exists('imagecreatetruecolor')) return null;
        try {
            $info = @getimagesize($src);
            if (!$info || $info[0] * $info[1] > 40000000) return null; // keep memory sane (40 MP)
            $img = match ($mime) {
                'image/jpeg' => @imagecreatefromjpeg($src),
                'image/png' => @imagecreatefrompng($src),
                'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
                default => false,
            };
            if (!$img) return null;
            if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
                $o = (int)(@exif_read_data($src)['Orientation'] ?? 1);
                $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
                if ($angle) { $r = imagerotate($img, $angle, 0); if ($r) { imagedestroy($img); $img = $r; } }
            }
            return self::writeThumb($img, $destBase);
        } catch (Throwable $e) {
            error_log('[receipts] thumb: ' . $e->getMessage());
            return null;
        }
    }

    /** The first page of a PDF, when pdftoppm is on the server. */
    private static function pdfThumb(string $src, string $destBase): ?string
    {
        if (!function_exists('imagecreatefrompng') || !RcReader::canRun('pdftoppm')) return null;
        $tmp = tempnam(sys_get_temp_dir(), 'rcpdf');
        try {
            @exec('pdftoppm -png -r 50 -f 1 -l 1 -singlefile ' . escapeshellarg($src) . ' ' . escapeshellarg($tmp) . ' 2>/dev/null', $o, $rc);
            if ($rc !== 0 || !is_file($tmp . '.png')) return null;
            $img = @imagecreatefrompng($tmp . '.png');
            return $img ? self::writeThumb($img, $destBase) : null;
        } finally {
            @unlink($tmp);
            @unlink($tmp . '.png');
        }
    }

    private static function writeThumb($img, string $destBase): ?string
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $tw = min(360, $w);
        $th = max(1, (int)round($h * $tw / $w));
        $t = imagecreatetruecolor($tw, $th);
        imagefill($t, 0, 0, imagecolorallocate($t, 255, 255, 255));
        imagecopyresampled($t, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagedestroy($img);
        $ok = false;
        if (function_exists('imagewebp')) {
            $dest = $destBase . '.thumb.webp';
            $ok = @imagewebp($t, $dest, 78);
        }
        if (!$ok) {
            $dest = $destBase . '.thumb.jpg';
            $ok = @imagejpeg($t, $dest, 80);
        }
        imagedestroy($t);
        if (!$ok) return null;
        @chmod($dest, 0640);
        return $dest;
    }
}
