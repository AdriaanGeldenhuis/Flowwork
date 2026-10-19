<?php
// /qi/p/_public.php — shared by the customer-facing pages (no login): the
// document is found by its unguessable link token, requests are rate limited
// per IP, forms carry a CSRF token from the visitor's own session, and the
// first look by someone outside the company marks the document as viewed.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../lib/QiSchema.php';
require_once __DIR__ . '/../lib/QiDocument.php';
require_once __DIR__ . '/../lib/QiTheme.php';
require_once __DIR__ . '/../lib/DocHtml.php';
require_once __DIR__ . '/../lib/QiActivity.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');

try { QiSchema::ensure($DB); } catch (Throwable $e) { error_log('[qi/p] schema: ' . $e->getMessage()); }

/** Per-IP sliding window; false when the visitor is over the limit. */
function qi_pub_allow(string $bucket, int $max, int $window): bool
{
    $dir = dirname(__DIR__, 2) . '/storage/qi/ratelimit';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return true;
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0');
    $file = $dir . '/pub_' . preg_replace('/[^a-z0-9_]/i', '_', $bucket) . '_' . substr(hash('sha256', $ip), 0, 20) . '.json';
    $fh = @fopen($file, 'c+');
    if (!$fh) return true;
    try {
        flock($fh, LOCK_EX);
        $now = time();
        $hits = json_decode(stream_get_contents($fh) ?: '[]', true);
        $hits = array_values(array_filter(is_array($hits) ? $hits : [], fn($t) => is_int($t) && $t > $now - $window));
        if (count($hits) >= $max) return false;
        $hits[] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($hits));
        return true;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** A friendly dead end (unknown link, too many requests). */
function qi_pub_stop(int $code, string $title, string $text): void
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . htmlspecialchars($title) . '</title>'
        . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4efe8;font:15px/1.6 Inter,system-ui,sans-serif;color:#2a1d18}'
        . 'main{max-width:440px;padding:32px;text-align:center}h1{font:400 30px/1.2 Georgia,serif;margin:0 0 10px}p{color:#6f5f55;margin:0}</style></head>'
        . '<body><main><h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($text) . '</p></main></body></html>';
    exit;
}

/** The document behind a link token, or a dead end. */
function qi_pub_load(PDO $db, string $type): array
{
    if (!qi_pub_allow('view', 240, 600)) qi_pub_stop(429, 'Slow down a little', 'Too many requests from your network. Please wait a few minutes and try again.');
    $token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
    if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) qi_pub_stop(404, 'Link not found', 'This link is incomplete or no longer valid. Please ask the sender for a new one.');
    $table = $type === 'quote' ? 'quotes' : 'invoices';
    $st = $db->prepare("SELECT * FROM `$table` WHERE public_token = ? LIMIT 1");
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $gone = !$row
        || ($type === 'quote' && in_array($row['status'], ['draft', 'cancelled'], true))
        || ($type === 'invoice' && (!empty($row['deleted_at']) || $row['status'] === 'draft'));
    if ($gone) qi_pub_stop(404, 'Link not found', 'This document is not available. Please ask the sender for a new link.');
    return [$row, $token];
}

/** First look by the customer: viewed status and the timeline (team members previewing do not count). */
function qi_pub_mark_viewed(PDO $db, string $type, array $row): void
{
    if (!empty($_SESSION['user_id']) && (int)($_SESSION['company_id'] ?? 0) === (int)$row['company_id']) return;
    $table = $type === 'quote' ? 'quotes' : 'invoices';
    try {
        $db->prepare("UPDATE `$table` SET viewed_at = COALESCE(viewed_at, NOW()), status = IF(status = 'sent', 'viewed', status), updated_at = updated_at
                       WHERE id = ? AND company_id = ?")->execute([(int)$row['id'], (int)$row['company_id']]);
        // one timeline entry per day is plenty
        $st = $db->prepare("SELECT 1 FROM qi_activity WHERE company_id = ? AND doc_type = ? AND doc_id = ? AND action = 'viewed' AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 1");
        $st->execute([(int)$row['company_id'], $type, (int)$row['id']]);
        if (!$st->fetchColumn()) QiActivity::log($db, (int)$row['company_id'], $type, (int)$row['id'], 'viewed', null, null, 'Customer');
    } catch (Throwable $e) {
        error_log('[qi/p] viewed: ' . $e->getMessage());
    }
}

/** Model + design + company for rendering. */
function qi_pub_model(PDO $db, string $type, array $row): array
{
    $m = QiDocument::load($db, (int)$row['company_id'], $type, (int)$row['id']);
    $st = $db->prepare("SELECT * FROM companies WHERE id = ?");
    $st->execute([(int)$row['company_id']]);
    $company = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $m['company']['raw'] = $company;
    $design = QiTheme::load($db, (int)$row['company_id'], $company);
    return [$m, $design, $company];
}

/** <head> + the opening of the page, in the company's accent colour. */
function qi_pub_head(string $title, array $design, string $headExtra = ''): void
{
    $accent = QiTheme::color($design['colors']['accent'] ?? '#7a1f30', '#7a1f30');
    $ink = QiTheme::idealInk($accent);
    $deep = QiTheme::mix($accent, '#000000', 0.28);
    $v = fn($f) => (string)(@filemtime(dirname(__DIR__, 2) . '/' . $f) ?: '1');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
        . '<meta name="robots" content="noindex,nofollow"><meta name="csrf-token" content="' . htmlspecialchars(Csrf::token()) . '">'
        . '<title>' . htmlspecialchars($title) . '</title>'
        . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Newsreader:ital,opsz,wght@0,6..72,300;0,6..72,400;1,6..72,400&display=swap" rel="stylesheet">'
        . '<link rel="stylesheet" href="/mail/css/mail.css?v=' . $v('mail/css/mail.css') . '">'
        . '<link rel="stylesheet" href="/qi/css/public.css?v=' . $v('qi/css/public.css') . '">'
        . DocHtml::headTags($design, $v('qi/css/doc.css')) . $headExtra
        . '</head><body class="fwm fwq-pub" data-theme="light" style="--brand:' . htmlspecialchars($accent) . ';--brand-ink:' . htmlspecialchars($ink) . ';--brand-deep:' . htmlspecialchars($deep) . '">';
    include dirname(__DIR__, 2) . '/mail/partials/icons.php';
    include dirname(__DIR__) . '/partials/icons.php';
}
