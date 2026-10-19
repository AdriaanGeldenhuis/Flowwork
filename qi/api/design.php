<?php
// /qi/api/design.php — the document designer.
//   GET                          design, presets, fonts, labels, permissions
//   POST {action:'preview', design, type, source:'sample'|'latest'}   → {html}
//   POST {action:'save', design}  → saved design (custom CSS: admins only)
//   POST {action:'undo'}          → the design before the last save
//   POST form action=pdf, design=<json>, type   → inline PDF of the unsaved design
//   POST multipart action=logo (file "logo")    → new logo url
//   POST {action:'logo_remove'}
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/QiDocument.php';
require_once __DIR__ . '/../lib/DocHtml.php';

$in = qi_is_post() ? qi_input() : $_GET;
$action = qi_str($in, 'action', 20) ?: (qi_is_post() ? '' : 'get');

/** The model to preview: the company's latest real document of that type, else the sample. */
function qi_design_model(string $type, string $source): array
{
    global $DB, $QI_COMPANY_ID;
    $company = qi_company();
    $type = in_array($type, QiDocument::TYPES, true) ? $type : 'quote';
    $m = null;
    if ($source === 'latest') {
        $table = QiDocument::table($type);
        $extra = $type === 'invoice' ? ' AND deleted_at IS NULL' : '';
        $st = $DB->prepare("SELECT id FROM `$table` WHERE company_id = ?$extra ORDER BY id DESC LIMIT 1");
        $st->execute([$QI_COMPANY_ID]);
        $id = (int)$st->fetchColumn();
        if ($id) $m = QiDocument::load($DB, $QI_COMPANY_ID, $type, $id);
    }
    if (!$m) $m = QiDocument::sample(QiDocument::company($DB, $QI_COMPANY_ID), $type);
    $m['company']['raw'] = $company;
    return $m;
}

/** A submitted design merged over the stored one (unknown keys dropped by normalize). */
function qi_design_from_input($raw): array
{
    global $DB, $QI_COMPANY_ID;
    $current = QiTheme::load($DB, $QI_COMPANY_ID, qi_company());
    if (!is_array($raw)) return $current;
    $design = QiTheme::normalize($raw);
    if (!qi_is_admin()) $design['custom_css'] = $current['custom_css'];
    return $design;
}

if ($action === 'get') {
    $design = QiTheme::load($DB, $QI_COMPANY_ID, qi_company());
    $presets = [];
    foreach (QiTheme::PRESETS as $key => $p) {
        $presets[] = ['key' => $key, 'name' => $p['name'], 'blurb' => $p['blurb'], 'colors' => $p['colors'], 'fonts' => $p['fonts'],
                      'layout' => $p['header']['layout'], 'table' => $p['table'], 'totals' => $p['totals']['style'], 'accent_bar' => $p['header']['accent_bar'],
                      'design' => QiTheme::normalize(QiTheme::applyPreset($design, $key))];
    }
    $fonts = [];
    foreach (QiTheme::FONTS as $key => [$name, $kind]) $fonts[] = ['key' => $key, 'name' => $name, 'kind' => $kind, 'stack' => QiTheme::fontStack($key)];
    $counts = [];
    foreach (QiDocument::TYPES as $t) {
        $table = QiDocument::table($t);
        $st = $DB->prepare("SELECT COUNT(*) FROM `$table` WHERE company_id = ?");
        $st->execute([$QI_COMPANY_ID]);
        $counts[$t] = (int)$st->fetchColumn();
    }
    $st = $DB->prepare("SELECT updated_at, (previous_json IS NOT NULL) AS can_undo FROM qi_designs WHERE company_id = ?");
    $st->execute([$QI_COMPANY_ID]);
    $meta = $st->fetch(PDO::FETCH_ASSOC) ?: ['updated_at' => null, 'can_undo' => 0];
    $company = qi_company();
    qi_json([
        'design' => $design,
        'defaults' => QiTheme::defaults(),
        'presets' => $presets,
        'fonts' => $fonts,
        'labels' => QiTheme::LABELS,
        'counts' => $counts,
        'saved_at' => $meta['updated_at'],
        'can_undo' => (bool)$meta['can_undo'],
        'can_edit' => qi_can_write(),
        'is_admin' => qi_is_admin(),
        'company' => ['name' => (string)($company['name'] ?? ''), 'logo_url' => (string)($company['logo_url'] ?? ''), 'vat_registered' => trim((string)($company['vat_number'] ?? '')) !== ''],
    ]);
}

qi_require_post();

if ($action === 'preview') {
    qi_rate_limit('qi_design_preview', 240, 60);
    $design = qi_design_from_input($in['design'] ?? null);
    $m = qi_design_model(qi_str($in, 'type', 20), qi_str($in, 'source', 10));
    qi_json(['html' => DocHtml::render($m, $design, ['editable' => true]), 'css' => $design['custom_css'], 'fonts' => QiTheme::fontLinks($design)]);
}

if ($action === 'pdf') {
    qi_rate_limit('qi_design_pdf', 30, 60);
    require_once __DIR__ . '/../lib/DocPdf.php';
    $raw = json_decode((string)($in['design'] ?? ''), true);
    $design = qi_design_from_input($raw);
    $m = qi_design_model(qi_str($in, 'type', 20), qi_str($in, 'source', 10));
    $bytes = DocPdf::render($m, $design);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="design-preview.pdf"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

qi_require_writer();

if ($action === 'save') {
    $design = qi_design_from_input($in['design'] ?? null);
    $saved = QiTheme::save($DB, $QI_COMPANY_ID, $design, $QI_USER_ID);
    qi_json(['design' => $saved, 'saved_at' => date('Y-m-d H:i:s'), 'can_undo' => true]);
}

if ($action === 'undo') {
    $prev = QiTheme::undo($DB, $QI_COMPANY_ID, $QI_USER_ID);
    if (!$prev) throw new QiUserError('There is no earlier design to go back to.');
    qi_json(['design' => $prev, 'saved_at' => date('Y-m-d H:i:s'), 'can_undo' => true]);
}

if ($action === 'logo') {
    qi_rate_limit('qi_logo', 20, 300);
    $url = qi_store_logo($_FILES['logo'] ?? null);
    qi_json(['logo_url' => $url]);
}

if ($action === 'logo_remove') {
    $company = qi_company();
    qi_remove_logo_files();
    $DB->prepare("UPDATE companies SET logo_url = NULL WHERE id = ?")->execute([$QI_COMPANY_ID]);
    qi_json(['logo_url' => '']);
}

qi_error('Unknown action', 400);

// ----------------------------------------------------------------- logo files

/**
 * Validate, re-encode (strips any payload) and store a logo as WebP under
 * /uploads/{company}/logo/ with a content-hashed name so caches refresh.
 */
function qi_store_logo($file): string
{
    global $DB, $QI_COMPANY_ID;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new QiUserError('Choose an image to upload.');
    if ($file['size'] > 3 * 1024 * 1024) throw new QiUserError('That image is larger than 3 MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) throw new QiUserError('Use a PNG, JPG or WebP image.');
    $dims = @getimagesize($file['tmp_name']);
    if (!$dims || $dims[0] < 8 || $dims[1] < 8 || $dims[0] > 5000 || $dims[1] > 5000 || $dims[0] * $dims[1] > 12000000) {
        throw new QiUserError('The image must be between 8 and 5000 pixels on each side.');
    }
    $im = match ($mime) {
        'image/png' => @imagecreatefrompng($file['tmp_name']),
        'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
        'image/webp' => @imagecreatefromwebp($file['tmp_name']),
    };
    if (!$im) throw new QiUserError('That image could not be read.');
    if (!imageistruecolor($im)) imagepalettetotruecolor($im); // PNG-8 → true colour (else imagewebp writes 0 bytes)
    imagealphablending($im, false);
    imagesavealpha($im, true);
    $w = imagesx($im);
    $h = imagesy($im);
    $scale = min(1.0, 1200 / max($w, $h));
    if ($scale < 1.0) {
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($im);
        $im = $dst;
    }
    ob_start();
    $ok = imagewebp($im, null, 92);
    $bytes = (string)ob_get_clean();
    imagedestroy($im);
    if (!$ok || strlen($bytes) < 64) throw new QiUserError('That image could not be converted.');

    $dir = dirname(__DIR__, 2) . '/uploads/' . $QI_COMPANY_ID . '/logo';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('Cannot create logo folder');
    $name = 'logo-' . substr(sha1($bytes), 0, 12) . '.webp';
    $tmp = $dir . '/.' . $name . '.tmp';
    if (file_put_contents($tmp, $bytes) !== strlen($bytes) || !rename($tmp, $dir . '/' . $name)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot write logo');
    }
    qi_remove_logo_files($name);
    $url = '/uploads/' . $QI_COMPANY_ID . '/logo/' . $name;
    $DB->prepare("UPDATE companies SET logo_url = ? WHERE id = ?")->execute([$url, $QI_COMPANY_ID]);
    return $url;
}

/** Delete this company's stored logo files except $keep. */
function qi_remove_logo_files(?string $keep = null): void
{
    global $QI_COMPANY_ID;
    $dir = dirname(__DIR__, 2) . '/uploads/' . $QI_COMPANY_ID . '/logo';
    foreach (glob($dir . '/logo*.webp') ?: [] as $f) {
        if ($keep !== null && basename($f) === $keep) continue;
        @unlink($f);
    }
}
