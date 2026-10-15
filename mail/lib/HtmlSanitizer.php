<?php
// mail/lib/HtmlSanitizer.php
//
// Allow-list HTML sanitizer for email bodies (DOM based, not regex). Output is
// rendered inside a sandboxed iframe (no allow-scripts) with a restrictive CSP,
// so this is the first of two layers, not the only one.

class HtmlSanitizer
{
    private const DROP_WITH_CONTENT = [
        'script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'noscript',
        'link', 'meta', 'base', 'title', 'template', 'svg', 'math', 'audio', 'video',
        'canvas', 'input', 'button', 'textarea', 'select', 'option', 'xml', 'head',
    ];
    private const UNWRAP = ['form', 'html', 'body', 'o:p'];

    private const ALLOWED_ATTRS = [
        'href', 'src', 'alt', 'title', 'width', 'height', 'style', 'class', 'id', 'align',
        'valign', 'bgcolor', 'color', 'border', 'cellpadding', 'cellspacing', 'colspan',
        'rowspan', 'dir', 'lang', 'face', 'size', 'background', 'name', 'start', 'type',
        'cite', 'datetime', 'span', 'scope', 'headers', 'nowrap', 'role', 'abbr', 'summary',
        'target', 'rel', 'data-fw-signature', 'data-fw-quote', 'data-fw-src',
    ];

    public const BLOCKED_PIXEL = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    /** The only local URL an <img> may point at (and only when allow_local is set). */
    private const LOCAL_ATTACHMENT = '#^/mail/api/attachment\.php\?id=\d+(&inline=1)?$#';

    /**
     * Options:
     *   block_remote  park remote images in data-fw-src (default true)
     *   cid_map       cid → URL for this message's own inline parts
     *   allow_local   accept /mail/api/attachment.php?id=N images. Only for content the
     *                 user wrote (drafts); received mail reaches its own parts via cid_map,
     *                 so it can never point at someone else's attachment.
     *   for_editor    content goes into a contenteditable in the app page: drop <style>
     *                 blocks and class/id so it cannot restyle the page around it.
     *
     * @param array{block_remote?:bool, cid_map?:array<string,string>, allow_local?:bool, for_editor?:bool} $opts
     * @return array{html:string, remote_images:bool}
     */
    public static function sanitize(string $html, array $opts = []): array
    {
        $blockRemote = (bool)($opts['block_remote'] ?? true);
        $cidMap = $opts['cid_map'] ?? [];
        $ctx = ['local' => (bool)($opts['allow_local'] ?? false), 'editor' => (bool)($opts['for_editor'] ?? false)];
        $remote = false;

        if (trim($html) === '') return ['html' => '', 'remote_images' => false];

        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        // The XML encoding hint makes libxml read the input as UTF-8.
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        // Keep <style> blocks wherever they are (including <head>).
        $styles = [];
        foreach (iterator_to_array($doc->getElementsByTagName('style')) as $st) {
            if (!$ctx['editor']) {
                $css = self::cleanCss($st->textContent, $blockRemote, $remote);
                if (trim($css) !== '') $styles[] = $css;
            }
            $st->parentNode?->removeChild($st);
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        $root = $body ?: $doc->documentElement;
        if (!$root) return ['html' => '', 'remote_images' => false];

        self::walk($root, $blockRemote, $cidMap, $ctx, $remote);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        if ($styles) {
            $out = '<style>' . implode("\n", $styles) . '</style>' . $out;
        }
        return ['html' => $out, 'remote_images' => $remote];
    }

    private static function walk(DOMNode $node, bool $blockRemote, array $cidMap, array $ctx, bool &$remote): void
    {
        $children = [];
        foreach ($node->childNodes as $c) $children[] = $c;

        foreach ($children as $child) {
            if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof DOMElement) continue;

            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $node->removeChild($child);
                continue;
            }
            if (in_array($tag, self::UNWRAP, true)) {
                self::walk($child, $blockRemote, $cidMap, $ctx, $remote);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            self::cleanAttributes($child, $tag, $blockRemote, $cidMap, $ctx, $remote);
            self::walk($child, $blockRemote, $cidMap, $ctx, $remote);
        }
    }

    private static function cleanAttributes(DOMElement $el, string $tag, bool $blockRemote, array $cidMap, array $ctx, bool &$remote): void
    {
        $attrs = [];
        foreach ($el->attributes as $a) $attrs[] = $a->name;

        foreach ($attrs as $name) {
            $lname = strtolower($name);
            $value = $el->getAttribute($name);
            if ((!in_array($lname, self::ALLOWED_ATTRS, true) && !str_starts_with($lname, 'aria-'))
                || ($ctx['editor'] && ($lname === 'class' || $lname === 'id'))) {
                $el->removeAttribute($name);
                continue;
            }
            switch ($lname) {
                case 'href':
                    $safe = self::safeHref($value);
                    if ($safe === null) {
                        $el->removeAttribute($name);
                    } else {
                        $el->setAttribute('href', $safe);
                    }
                    break;
                case 'src':
                case 'background':
                    $res = self::safeSrc($value, $blockRemote, $cidMap, $ctx['local'] && $lname === 'src', $remote);
                    if ($res === null) {
                        $el->removeAttribute($name);
                    } elseif ($res['blocked']) {
                        $el->setAttribute('data-fw-src', $res['url']);
                        $el->setAttribute($lname, $lname === 'src' ? self::BLOCKED_PIXEL : '');
                        if ($lname === 'src') {
                            // Keep the layout of blocked images without blowing a 1x1
                            // placeholder up into a square: reserve the declared height,
                            // hide images that declare none.
                            $h = (int)$el->getAttribute('height');
                            $style = rtrim((string)$el->getAttribute('style'), '; ');
                            $style .= ($style !== '' ? ';' : '') . ($h > 0 ? 'height:' . min($h, 600) . 'px;background:#f3efe9' : 'display:none');
                            $el->setAttribute('style', $style);
                        }
                    } else {
                        $el->setAttribute($lname, $res['url']);
                    }
                    break;
                case 'style':
                    $css = self::cleanCss($value, $blockRemote, $remote);
                    if (trim($css) === '') $el->removeAttribute($name); else $el->setAttribute('style', $css);
                    break;
                case 'target':
                case 'rel':
                    $el->removeAttribute($name); // re-added uniformly below
                    break;
            }
        }

        if ($tag === 'a' && $el->hasAttribute('href')) {
            $href = $el->getAttribute('href');
            if (!str_starts_with($href, '#')) {
                $el->setAttribute('target', '_blank');
                $el->setAttribute('rel', 'noopener noreferrer nofollow');
            }
        }
    }

    public static function safeHref(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') return null;
        if ($url[0] === '#') return $url;
        $compact = strtolower(preg_replace('/[\s\x00-\x1f]+/', '', $url));
        if (preg_match('#^(https?:|mailto:|tel:)#', $compact)) return $url;
        return null;
    }

    /** @return array{url:string, blocked:bool}|null */
    private static function safeSrc(string $url, bool $blockRemote, array $cidMap, bool $allowLocal, bool &$remote): ?array
    {
        $url = trim($url);
        $lower = strtolower($url);
        if (str_starts_with($lower, 'cid:')) {
            $cid = strtolower(trim(substr($url, 4), '<> '));
            return isset($cidMap[$cid]) ? ['url' => $cidMap[$cid], 'blocked' => false] : null;
        }
        if (preg_match('#^data:image/(png|jpe?g|gif|webp|bmp);base64,#i', $url)) {
            return ['url' => $url, 'blocked' => false];
        }
        if (preg_match('#^(https?:)?//#i', $url)) {
            $remote = true;
            if (str_starts_with($url, '//')) $url = 'https:' . $url;
            return ['url' => $url, 'blocked' => $blockRemote];
        }
        if ($allowLocal) {
            $plain = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match(self::LOCAL_ATTACHMENT, $plain)) return ['url' => $plain, 'blocked' => false];
        }
        return null;
    }

    private static function cleanCss(string $css, bool $blockRemote, bool &$remote): string
    {
        // Strip anything that can execute or import.
        $css = preg_replace('/@import[^;]*;?/i', '', $css);
        $css = preg_replace('/expression\s*\(|behaviou?r\s*:|-moz-binding|javascript\s*:|vbscript\s*:/i', '', $css);
        $css = preg_replace_callback('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', function ($m) use ($blockRemote, &$remote) {
            $u = trim($m[2]);
            if (preg_match('#^data:image/#i', $u)) return $m[0];
            if (preg_match('#^(https?:)?//#i', $u)) {
                $remote = true;
                return $blockRemote ? 'none' : 'url("' . str_replace('"', '%22', $u) . '")';
            }
            return 'none';
        }, $css);
        // Position:fixed overlays could cover our UI-like chrome inside the frame.
        $css = preg_replace('/position\s*:\s*fixed/i', 'position:static', $css);
        return $css;
    }

    /** HTML → readable plain text (snippets, text/plain alternative, search). */
    public static function toText(string $html, int $max = 0): string
    {
        if (trim($html) === '') return '';
        $html = preg_replace('#<(style|script|head|title)\b[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</(p|div|tr|h[1-6]|blockquote|table|ul|ol|pre)>#i', "\n", $html);
        $html = preg_replace('#<li\b[^>]*>#i', "\n• ", $html);
        $html = preg_replace_callback('#<a\b[^>]*href\s*=\s*([\'"])(https?://[^\'"]+)\1[^>]*>(.*?)</a>#is', function ($m) {
            $label = trim(strip_tags($m[3]));
            return ($label === '' || $label === $m[2] || str_contains($m[2], $label)) ? $m[2] : "$label ($m[2])";
        }, $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/ *\n */", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        $text = trim($text);
        if ($max > 0 && mb_strlen($text) > $max) $text = mb_substr($text, 0, $max);
        return $text;
    }

    /** Stored content that may be plain text (older textarea-based editors) → HTML. */
    public static function ensureHtml(string $s): string
    {
        if ($s === '' || $s !== strip_tags($s)) return $s;
        return nl2br(htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }

    /** Plain text → safe HTML with clickable links and preserved line breaks. */
    public static function fromText(string $text): string
    {
        $esc = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $esc = preg_replace('#\bhttps?://[^\s<>"\']+#i', '<a href="$0" target="_blank" rel="noopener noreferrer nofollow">$0</a>', $esc);
        return '<div style="white-space:pre-wrap;word-wrap:break-word">' . $esc . '</div>';
    }

    /** One-line preview: drops quoted replies and collapses whitespace. */
    public static function snippet(?string $text, int $len = 160): string
    {
        $text = (string)$text;
        if ($text === '') return '';
        $lines = preg_split("/\r?\n/", $text);
        $keep = [];
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '' || str_starts_with($t, '>')) continue;
            if (preg_match('/^(On .{4,120} wrote:|-{2,} ?Original Message ?-{2,}|-{5,} ?Forwarded message)/i', $t)) break;
            $keep[] = $t;
            if (mb_strlen(implode(' ', $keep)) > $len) break;
        }
        $s = preg_replace('/\s+/u', ' ', implode(' ', $keep));
        return mb_strlen($s) > $len ? rtrim(mb_substr($s, 0, $len - 1)) . '…' : $s;
    }
}
