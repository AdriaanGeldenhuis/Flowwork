<?php
/**
 * QiTheme — the look of quotes, invoices and credit notes.
 *
 * One design per company (table qi_designs, see QiSchema) drives every place a
 * document appears: the on-screen view, the editor's live preview, the printed
 * page, the public link, the emailed / downloaded PDF and the Drive copy. The
 * HTML renderer (DocHtml) and the PDF renderer (DocPdf) both read the resolved
 * values below, so what the designer shows is what the customer receives.
 *
 * A design is a nested array (stored as JSON). normalize() is the single
 * validator: every value is whitelisted or clamped, unknown keys are dropped.
 * Companies that never opened the designer get their legacy branding
 * (companies.primary_color, qi_template, qi_font_family …) translated by
 * fromLegacy(); saving mirrors the main values back to those columns so older
 * code paths stay consistent.
 */
final class QiTheme
{
    /** Document fonts. Files live in /includes/pdf/fonts (Latin subsets, OFL). */
    public const FONTS = [
        'inter'             => ['Inter', 'sans', 'Inter'],
        'manrope'           => ['Manrope', 'sans', 'Manrope'],
        'dm-sans'           => ['DM Sans', 'sans', 'DM+Sans'],
        'work-sans'         => ['Work Sans', 'sans', 'Work+Sans'],
        'montserrat'        => ['Montserrat', 'sans', 'Montserrat'],
        'poppins'           => ['Poppins', 'sans', 'Poppins'],
        'lato'              => ['Lato', 'sans', 'Lato'],
        'plex-sans'         => ['IBM Plex Sans', 'sans', 'IBM+Plex+Sans'],
        'arimo'             => ['Arimo', 'sans', 'Arimo'],
        'newsreader'        => ['Newsreader', 'serif', 'Newsreader'],
        'playfair'          => ['Playfair Display', 'serif', 'Playfair+Display'],
        'lora'              => ['Lora', 'serif', 'Lora'],
        'merriweather'      => ['Merriweather', 'serif', 'Merriweather'],
        'cormorant'         => ['Cormorant Garamond', 'serif', 'Cormorant+Garamond'],
        'eb-garamond'       => ['EB Garamond', 'serif', 'EB+Garamond'],
        'libre-baskerville' => ['Libre Baskerville', 'serif', 'Libre+Baskerville'],
        'gelasio'           => ['Gelasio', 'serif', 'Gelasio'],
    ];

    /** Legacy companies.qi_font_family values. */
    private const LEGACY_FONTS = ['system-ui' => 'inter', 'inter' => 'inter', 'montserrat' => 'montserrat', 'helvetica' => 'arimo', 'georgia' => 'gelasio'];

    public const HEADER_LAYOUTS = ['split', 'stacked', 'centered', 'banner', 'band'];
    public const TABLE_STYLES = ['lines', 'striped', 'boxed', 'minimal'];
    public const HEAD_STYLES = ['filled', 'soft', 'underline', 'plain'];
    public const TOTALS_STYLES = ['stack', 'box', 'band'];
    public const ACCENT_BARS = ['none', 'top', 'left', 'bottom'];
    public const HEADING_ROWS = ['band', 'underline', 'plain'];

    /** Labels a company can rename (key => default). */
    public const LABELS = [
        'quote'       => 'Quotation',
        'invoice'     => 'Invoice',
        'tax_invoice' => 'Tax Invoice',
        'credit_note' => 'Credit Note',
        'number'      => 'No.',
        'date'        => 'Date',
        'valid_until' => 'Valid until',
        'due_date'    => 'Due date',
        'reference'   => 'Reference',
        'bill_to'     => 'Bill to',
        'from'        => 'From',
        'description' => 'Description',
        'qty'         => 'Qty',
        'unit'        => 'Unit',
        'price'       => 'Unit price',
        'discount'    => 'Discount',
        'vat'         => 'VAT',
        'amount'      => 'Amount',
        'subtotal'    => 'Subtotal',
        'total'       => 'Total',
        'balance_due' => 'Balance due',
        'paid'        => 'Paid',
        'section_total' => 'Section total',
        'terms'       => 'Terms & conditions',
        'notes'       => 'Notes',
        'payment'     => 'Payment details',
        'schedule'    => 'Payment schedule',
        'payments'    => 'Payments received',
        'accept'      => 'Accepted by',
        'pay_online'  => 'Pay online',
        'view_online' => 'View & accept online',
    ];

    /** Presets: a starting point the designer applies on top of the defaults. */
    public const PRESETS = [
        'maison' => [
            'name' => 'Maison', 'blurb' => 'Serif headings, bordeaux and champagne gold',
            'fonts' => ['heading' => 'newsreader', 'body' => 'inter'],
            'colors' => ['accent' => '#7a1f30', 'accent2' => '#b08d57', 'heading' => '#1d1613', 'text' => '#3b302a', 'muted' => '#85766b', 'paper' => '#fffdf9', 'rule' => '#e6dccf', 'head_bg' => '#7a1f30', 'head_text' => '#fff8f0', 'stripe' => '#f8f3ec'],
            'header' => ['layout' => 'split', 'ornament' => true, 'accent_bar' => 'none'],
            'table' => ['style' => 'lines', 'head' => 'underline', 'heading_row' => 'band', 'radius' => 10],
            'totals' => ['style' => 'stack'],
        ],
        'modern' => [
            'name' => 'Modern', 'blurb' => 'Clean sans, filled table head, striped rows',
            'fonts' => ['heading' => 'inter', 'body' => 'inter'],
            'colors' => ['accent' => '#1f2937', 'accent2' => '#f59e0b', 'heading' => '#111827', 'text' => '#374151', 'muted' => '#6b7280', 'paper' => '#ffffff', 'rule' => '#e5e7eb', 'head_bg' => '#1f2937', 'head_text' => '#ffffff', 'stripe' => '#f9fafb'],
            'header' => ['layout' => 'split', 'ornament' => false, 'accent_bar' => 'top'],
            'table' => ['style' => 'striped', 'head' => 'filled', 'heading_row' => 'band', 'radius' => 8],
            'totals' => ['style' => 'box'],
        ],
        'classic' => [
            'name' => 'Classic', 'blurb' => 'Centred letterhead, serif type, ruled lines',
            'fonts' => ['heading' => 'libre-baskerville', 'body' => 'lora'],
            'colors' => ['accent' => '#1e3a5f', 'accent2' => '#8a6d3b', 'heading' => '#1e3a5f', 'text' => '#2d3748', 'muted' => '#718096', 'paper' => '#ffffff', 'rule' => '#cbd5e0', 'head_bg' => '#1e3a5f', 'head_text' => '#ffffff', 'stripe' => '#f7fafc'],
            'header' => ['layout' => 'centered', 'ornament' => true, 'accent_bar' => 'none'],
            'table' => ['style' => 'lines', 'head' => 'underline', 'heading_row' => 'underline', 'radius' => 0],
            'totals' => ['style' => 'stack'],
        ],
        'minimal' => [
            'name' => 'Minimal', 'blurb' => 'Quiet, airy and monochrome',
            'fonts' => ['heading' => 'dm-sans', 'body' => 'dm-sans'],
            'colors' => ['accent' => '#111111', 'accent2' => '#9ca3af', 'heading' => '#111111', 'text' => '#3f3f46', 'muted' => '#8b8b93', 'paper' => '#ffffff', 'rule' => '#ececec', 'head_bg' => '#111111', 'head_text' => '#ffffff', 'stripe' => '#fafafa'],
            'header' => ['layout' => 'stacked', 'ornament' => false, 'accent_bar' => 'none'],
            'table' => ['style' => 'minimal', 'head' => 'plain', 'heading_row' => 'plain', 'radius' => 0, 'density' => 'airy'],
            'totals' => ['style' => 'stack'],
        ],
        'bold' => [
            'name' => 'Bold', 'blurb' => 'A full-colour letterhead band',
            'fonts' => ['heading' => 'poppins', 'body' => 'poppins'],
            'colors' => ['accent' => '#e11d48', 'accent2' => '#111827', 'heading' => '#111827', 'text' => '#374151', 'muted' => '#6b7280', 'paper' => '#ffffff', 'rule' => '#e5e7eb', 'head_bg' => '#111827', 'head_text' => '#ffffff', 'stripe' => '#fff1f2'],
            'header' => ['layout' => 'banner', 'ornament' => false, 'accent_bar' => 'none'],
            'table' => ['style' => 'boxed', 'head' => 'filled', 'heading_row' => 'band', 'radius' => 6],
            'totals' => ['style' => 'band'],
        ],
        'corporate' => [
            'name' => 'Corporate', 'blurb' => 'Side accent bar, structured and calm',
            'fonts' => ['heading' => 'plex-sans', 'body' => 'plex-sans'],
            'colors' => ['accent' => '#0b3954', 'accent2' => '#087e8b', 'heading' => '#0b3954', 'text' => '#33475b', 'muted' => '#7c8b99', 'paper' => '#ffffff', 'rule' => '#dbe3ea', 'head_bg' => '#0b3954', 'head_text' => '#ffffff', 'stripe' => '#f3f7fa'],
            'header' => ['layout' => 'split', 'ornament' => false, 'accent_bar' => 'left'],
            'table' => ['style' => 'lines', 'head' => 'filled', 'heading_row' => 'band', 'radius' => 4],
            'totals' => ['style' => 'box'],
        ],
        'atelier' => [
            'name' => 'Atelier', 'blurb' => 'Garamond elegance on warm ivory',
            'fonts' => ['heading' => 'cormorant', 'body' => 'lato'],
            'colors' => ['accent' => '#9a7136', 'accent2' => '#2a2420', 'heading' => '#2a2420', 'text' => '#3d3631', 'muted' => '#8f8478', 'paper' => '#fffcf6', 'rule' => '#e9dfcf', 'head_bg' => '#2a2420', 'head_text' => '#f7efe2', 'stripe' => '#faf5ec'],
            'header' => ['layout' => 'centered', 'ornament' => true, 'accent_bar' => 'none'],
            'table' => ['style' => 'lines', 'head' => 'underline', 'heading_row' => 'underline', 'radius' => 0],
            'totals' => ['style' => 'stack'],
        ],
        'studio' => [
            'name' => 'Studio', 'blurb' => 'Soft tinted band, rounded and friendly',
            'fonts' => ['heading' => 'manrope', 'body' => 'manrope'],
            'colors' => ['accent' => '#6d28d9', 'accent2' => '#0ea5e9', 'heading' => '#1e1b4b', 'text' => '#3f3d56', 'muted' => '#7c7a96', 'paper' => '#ffffff', 'rule' => '#e7e5f4', 'head_bg' => '#6d28d9', 'head_text' => '#ffffff', 'stripe' => '#f7f5ff'],
            'header' => ['layout' => 'band', 'ornament' => false, 'accent_bar' => 'none'],
            'table' => ['style' => 'striped', 'head' => 'soft', 'heading_row' => 'band', 'radius' => 14],
            'totals' => ['style' => 'box'],
        ],
    ];

    /** The full default design (Maison). Every key normalize() knows lives here. */
    public static function defaults(): array
    {
        $d = [
            'v' => 1,
            'preset' => 'maison',
            'page' => ['size' => 'A4', 'margins' => 'normal'],
            'fonts' => ['heading' => 'newsreader', 'body' => 'inter', 'scale' => 100],
            'colors' => [],
            'header' => [
                'layout' => 'split', 'logo_position' => 'left', 'logo_size' => 160, 'show_name' => true,
                'title_size' => 'lg', 'ornament' => true, 'accent_bar' => 'none',
            ],
            'company' => ['address' => true, 'phone' => true, 'email' => true, 'website' => true, 'vat' => true, 'tax' => false, 'reg' => true],
            'customer' => ['address' => true, 'contact' => true, 'phone' => false, 'email' => true, 'vat' => true, 'reg' => false],
            'table' => [
                'style' => 'lines', 'head' => 'underline', 'heading_row' => 'band', 'density' => 'normal', 'radius' => 10,
                'section_totals' => true,
                'cols' => ['num' => true, 'qty' => true, 'unit' => false, 'price' => true, 'discount' => 'auto', 'vat' => 'auto'],
            ],
            'totals' => ['style' => 'stack', 'show_zero_discount' => false],
            'sections' => [
                'terms' => true, 'notes' => true, 'payment' => true, 'milestones' => true, 'payments' => true,
                'signature' => false, 'online_link' => true,
            ],
            'stamp' => ['status' => true],
            'labels' => self::LABELS,
            'footer' => ['quote' => '', 'invoice' => '', 'credit_note' => '', 'page_numbers' => true],
            'custom_css' => '',
        ];
        return self::applyPreset($d, 'maison');
    }

    /** Overlay a preset's fonts, colours and layout onto a design (labels, toggles and text survive). */
    public static function applyPreset(array $design, string $preset): array
    {
        $p = self::PRESETS[$preset] ?? null;
        if (!$p) return $design;
        $design['preset'] = $preset;
        $design['fonts'] = array_merge($design['fonts'] ?? [], $p['fonts']);
        $design['colors'] = $p['colors'];
        foreach (['header', 'table', 'totals'] as $k) {
            $design[$k] = array_merge($design[$k] ?? [], $p[$k]);
        }
        return $design;
    }

    // -------------------------------------------------------------- storage

    /** The company's design (stored, else translated from legacy branding), normalised. */
    public static function load(PDO $db, int $companyId, ?array $companyRow = null): array
    {
        try {
            $st = $db->prepare("SELECT design_json FROM qi_designs WHERE company_id = ?");
            $st->execute([$companyId]);
            $json = $st->fetchColumn();
            if (is_string($json) && $json !== '') {
                $stored = json_decode($json, true);
                if (is_array($stored)) return self::normalize($stored);
            }
        } catch (Throwable $e) {
            // Table not created yet (QiSchema::ensure not run) — fall back to legacy branding
        }
        if ($companyRow === null) {
            $st = $db->prepare("SELECT * FROM companies WHERE id = ?");
            $st->execute([$companyId]);
            $companyRow = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        return self::fromLegacy($companyRow);
    }

    /**
     * Persist a design (already normalised) and mirror the values older code
     * reads from companies.*. Keeps the previous version for one-step undo.
     */
    public static function save(PDO $db, int $companyId, array $design, int $userId): array
    {
        $design = self::normalize($design);
        $json = json_encode($design, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $db->prepare("INSERT INTO qi_designs (company_id, design_json, previous_json, updated_by, updated_at)
                      VALUES (?, ?, NULL, ?, NOW())
                      ON DUPLICATE KEY UPDATE previous_json = design_json, design_json = VALUES(design_json),
                                              updated_by = VALUES(updated_by), updated_at = NOW()")
           ->execute([$companyId, $json, $userId]);

        // Mirror into the legacy branding columns (best-effort: columns may be missing on old schemas)
        $legacyTemplate = in_array($design['preset'], ['modern', 'classic', 'minimal', 'bold', 'corporate'], true) ? $design['preset'] : 'modern';
        $legacyFont = $design['fonts']['body'] === 'gelasio' ? 'georgia' : (in_array($design['fonts']['body'], ['inter', 'montserrat'], true) ? $design['fonts']['body'] : 'system-ui');
        $c = $design['colors'];
        $map = [
            'primary_color' => $c['accent'], 'secondary_color' => $c['accent2'], 'qi_heading_color' => $c['heading'],
            'qi_text_color' => $c['text'], 'qi_bg_color' => $c['paper'], 'qi_table_header_text' => $c['head_text'],
            'qi_template' => $legacyTemplate, 'qi_font_family' => $legacyFont,
            'qi_border_radius' => $design['table']['radius'], 'qi_logo_size' => $design['header']['logo_size'],
            'qi_logo_position' => $design['header']['logo_position'],
            'qi_quote_title' => mb_substr(mb_strtoupper($design['labels']['quote']), 0, 50),
            'qi_invoice_title' => mb_substr(mb_strtoupper($design['labels']['invoice']), 0, 50),
            'qi_show_company_address' => (int)$design['company']['address'], 'qi_show_company_phone' => (int)$design['company']['phone'],
            'qi_show_company_email' => (int)$design['company']['email'], 'qi_show_company_website' => (int)$design['company']['website'],
            'qi_show_vat_number' => (int)$design['company']['vat'], 'qi_show_tax_number' => (int)$design['company']['tax'],
            'qi_show_reg_number' => (int)$design['company']['reg'], 'qi_show_payment_details' => (int)$design['sections']['payment'],
            'quote_footer_text' => $design['footer']['quote'], 'invoice_footer_text' => $design['footer']['invoice'],
            'qi_custom_css' => $design['custom_css'],
        ];
        foreach ($map as $col => $val) {
            try {
                $db->prepare("UPDATE companies SET `$col` = ? WHERE id = ?")->execute([$val, $companyId]);
            } catch (Throwable $e) {
                // Column absent on this schema revision — the design JSON is authoritative anyway
            }
        }
        return $design;
    }

    /** Restore the version saved before the last save (returns it, or null). */
    public static function undo(PDO $db, int $companyId, int $userId): ?array
    {
        $st = $db->prepare("SELECT previous_json FROM qi_designs WHERE company_id = ?");
        $st->execute([$companyId]);
        $prev = $st->fetchColumn();
        $design = is_string($prev) ? json_decode($prev, true) : null;
        if (!is_array($design)) return null;
        return self::save($db, $companyId, $design, $userId);
    }

    /** Translate the pre-designer branding columns into a design. */
    public static function fromLegacy(array $c): array
    {
        $legacy = (string)($c['qi_template'] ?? '');
        $d = self::applyPreset(self::defaults(), isset(self::PRESETS[$legacy]) ? $legacy : 'maison');
        $hasBranding = !empty($c['primary_color']) && strtolower((string)$c['primary_color']) !== '#fbbf24';
        if ($hasBranding || $legacy !== '') {
            $accent = self::color($c['primary_color'] ?? null, $d['colors']['accent']);
            $d['colors']['accent'] = $accent;
            $d['colors']['head_bg'] = $accent;
            $d['colors']['accent2'] = self::color($c['secondary_color'] ?? null, $d['colors']['accent2']);
            $d['colors']['head_text'] = self::color($c['qi_table_header_text'] ?? null, self::idealInk($accent));
            if (!empty($c['qi_heading_color'])) $d['colors']['heading'] = self::color($c['qi_heading_color'], $d['colors']['heading']);
            if (!empty($c['qi_text_color'])) $d['colors']['text'] = self::color($c['qi_text_color'], $d['colors']['text']);
            if (!empty($c['qi_bg_color'])) $d['colors']['paper'] = self::color($c['qi_bg_color'], $d['colors']['paper']);
        }
        $font = self::LEGACY_FONTS[(string)($c['qi_font_family'] ?? '')] ?? null;
        if ($font) $d['fonts']['body'] = $font;
        if (isset($c['qi_border_radius'])) $d['table']['radius'] = (int)$c['qi_border_radius'];
        if (isset($c['qi_logo_size'])) $d['header']['logo_size'] = (int)$c['qi_logo_size'];
        if (!empty($c['qi_logo_position'])) $d['header']['logo_position'] = (string)$c['qi_logo_position'];
        foreach (['address' => 'qi_show_company_address', 'phone' => 'qi_show_company_phone', 'email' => 'qi_show_company_email',
                  'website' => 'qi_show_company_website', 'vat' => 'qi_show_vat_number', 'tax' => 'qi_show_tax_number', 'reg' => 'qi_show_reg_number'] as $k => $col) {
            if (array_key_exists($col, $c) && $c[$col] !== null) $d['company'][$k] = (bool)(int)$c[$col];
        }
        if (array_key_exists('qi_show_payment_details', $c) && $c['qi_show_payment_details'] !== null) {
            $d['sections']['payment'] = (bool)(int)$c['qi_show_payment_details'];
        }
        if (!empty($c['qi_quote_title'])) $d['labels']['quote'] = self::titleCase((string)$c['qi_quote_title']);
        if (!empty($c['qi_invoice_title'])) $d['labels']['invoice'] = self::titleCase((string)$c['qi_invoice_title']);
        $d['footer']['quote'] = (string)($c['quote_footer_text'] ?? '');
        $d['footer']['invoice'] = (string)($c['invoice_footer_text'] ?? '');
        $d['footer']['credit_note'] = (string)($c['invoice_footer_text'] ?? '');
        $d['custom_css'] = (string)($c['qi_custom_css'] ?? '');
        return self::normalize($d);
    }

    // ------------------------------------------------------------ validation

    /** Validate and complete a design: whitelists, clamps, colours; unknown keys dropped. */
    public static function normalize(array $in): array
    {
        $def = self::defaults();
        $preset = isset(self::PRESETS[$in['preset'] ?? '']) ? $in['preset'] : 'maison';
        $base = self::applyPreset($def, $preset);
        $g = fn(array $path, $fallback) => self::dig($in, $path, $fallback);
        $pick = fn($v, array $allowed, $fallback) => in_array($v, $allowed, true) ? $v : $fallback;
        $bool = fn($v, bool $fallback) => is_bool($v) ? $v : (is_numeric($v) ? (bool)(int)$v : ($v === 'true' ? true : ($v === 'false' ? false : $fallback)));
        $text = fn($v, string $fallback, int $max) => is_string($v) ? mb_substr(trim(str_replace(["\r\n", "\r"], "\n", $v)), 0, $max) : $fallback;

        $out = ['v' => 1, 'preset' => $preset];
        $out['page'] = [
            'size' => $pick($g(['page', 'size'], 'A4'), ['A4', 'Letter'], 'A4'),
            'margins' => $pick($g(['page', 'margins'], 'normal'), ['compact', 'normal', 'roomy'], 'normal'),
        ];
        $out['fonts'] = [
            'heading' => $pick($g(['fonts', 'heading'], $base['fonts']['heading']), array_keys(self::FONTS), $base['fonts']['heading']),
            'body' => $pick($g(['fonts', 'body'], $base['fonts']['body']), array_keys(self::FONTS), $base['fonts']['body']),
            'scale' => max(85, min(120, (int)$g(['fonts', 'scale'], 100))),
        ];
        $out['colors'] = [];
        foreach ($base['colors'] as $k => $v) {
            $out['colors'][$k] = self::color($g(['colors', $k], $v), $v);
        }
        $h = $base['header'];
        $out['header'] = [
            'layout' => $pick($g(['header', 'layout'], $h['layout']), self::HEADER_LAYOUTS, $h['layout']),
            'logo_position' => $pick($g(['header', 'logo_position'], $h['logo_position']), ['left', 'center', 'right'], 'left'),
            'logo_size' => max(60, min(320, (int)$g(['header', 'logo_size'], $h['logo_size']))),
            'show_name' => $bool($g(['header', 'show_name'], $h['show_name']), true),
            'title_size' => $pick($g(['header', 'title_size'], $h['title_size']), ['sm', 'md', 'lg', 'xl'], 'lg'),
            'ornament' => $bool($g(['header', 'ornament'], $h['ornament']), (bool)$h['ornament']),
            'accent_bar' => $pick($g(['header', 'accent_bar'], $h['accent_bar']), self::ACCENT_BARS, $h['accent_bar']),
        ];
        foreach (['company', 'customer'] as $grp) {
            foreach ($def[$grp] as $k => $v) $out[$grp][$k] = $bool($g([$grp, $k], $v), $v);
        }
        $t = $base['table'];
        $out['table'] = [
            'style' => $pick($g(['table', 'style'], $t['style']), self::TABLE_STYLES, $t['style']),
            'head' => $pick($g(['table', 'head'], $t['head']), self::HEAD_STYLES, $t['head']),
            'heading_row' => $pick($g(['table', 'heading_row'], $t['heading_row']), self::HEADING_ROWS, $t['heading_row']),
            'density' => $pick($g(['table', 'density'], $t['density'] ?? 'normal'), ['compact', 'normal', 'airy'], 'normal'),
            'radius' => max(0, min(24, (int)$g(['table', 'radius'], $t['radius']))),
            'section_totals' => $bool($g(['table', 'section_totals'], true), true),
            'cols' => [
                'num' => $bool($g(['table', 'cols', 'num'], true), true),
                'qty' => $bool($g(['table', 'cols', 'qty'], true), true),
                'unit' => $bool($g(['table', 'cols', 'unit'], false), false),
                'price' => $bool($g(['table', 'cols', 'price'], true), true),
                'discount' => $pick($g(['table', 'cols', 'discount'], 'auto'), ['auto', 'on', 'off'], 'auto'),
                'vat' => $pick($g(['table', 'cols', 'vat'], 'auto'), ['auto', 'on', 'off'], 'auto'),
            ],
        ];
        $out['totals'] = [
            'style' => $pick($g(['totals', 'style'], $base['totals']['style']), self::TOTALS_STYLES, $base['totals']['style']),
            'show_zero_discount' => $bool($g(['totals', 'show_zero_discount'], false), false),
        ];
        foreach ($def['sections'] as $k => $v) $out['sections'][$k] = $bool($g(['sections', $k], $v), $v);
        $out['stamp'] = ['status' => $bool($g(['stamp', 'status'], true), true)];
        foreach (self::LABELS as $k => $v) {
            $l = $text($g(['labels', $k], $v), $v, 40);
            $out['labels'][$k] = $l !== '' ? $l : $v;
        }
        $out['footer'] = [
            'quote' => $text($g(['footer', 'quote'], ''), '', 1200),
            'invoice' => $text($g(['footer', 'invoice'], ''), '', 1200),
            'credit_note' => $text($g(['footer', 'credit_note'], ''), '', 1200),
            'page_numbers' => $bool($g(['footer', 'page_numbers'], true), true),
        ];
        $out['custom_css'] = self::sanitizeCss($text($g(['custom_css'], ''), '', 8000));
        return $out;
    }

    // --------------------------------------------------- per-document values

    /**
     * Values a renderer needs for one document: title (SARS: a VAT vendor's
     * invoice must read "tax invoice"), footer, and the supplier fields that a
     * tax invoice / credit note may not hide (s20(4), s21(3)).
     */
    public static function forDocument(array $design, string $type, array $company): array
    {
        $vatRegistered = trim((string)($company['vat_number'] ?? '')) !== '';
        $l = $design['labels'];
        if ($type === 'quote') {
            $title = $l['quote'];
        } elseif ($type === 'credit_note') {
            $title = $l['credit_note'];
        } else {
            $title = $vatRegistered ? (stripos($l['invoice'], 'tax invoice') !== false ? $l['invoice'] : $l['tax_invoice']) : $l['invoice'];
        }
        $companyShow = $design['company'];
        $customerShow = $design['customer'];
        if ($vatRegistered && $type !== 'quote') {
            $companyShow['vat'] = true;
            $companyShow['address'] = true;
            $customerShow['vat'] = true;
            $customerShow['address'] = true;
        }
        $footerKey = $type === 'credit_note' ? 'credit_note' : ($type === 'quote' ? 'quote' : 'invoice');
        return [
            'title' => $title,
            'footer' => $design['footer'][$footerKey] ?? '',
            'company' => $companyShow,
            'customer' => $customerShow,
            'vat_registered' => $vatRegistered,
        ];
    }

    /** <link> for the Google Fonts the design uses (screen rendering). */
    public static function fontLinks(array $design): string
    {
        $fams = [];
        foreach (array_unique([$design['fonts']['heading'], $design['fonts']['body']]) as $k) {
            $f = self::FONTS[$k] ?? null;
            if (!$f) continue;
            $fams[] = 'family=' . $f[2] . ($k === 'manrope' ? ':wght@400;700' : ':ital,wght@0,400;0,700;1,400');
        }
        if (!$fams) return '';
        return '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
             . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?' . htmlspecialchars(implode('&', $fams)) . '&display=swap">';
    }

    /** CSS font-family stack for a font key. */
    public static function fontStack(string $key): string
    {
        $f = self::FONTS[$key] ?? self::FONTS['inter'];
        return "'" . $f[0] . "', " . ($f[1] === 'serif' ? "Georgia, 'Times New Roman', serif" : "system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif");
    }

    /** Absolute path of a font file for the PDF ('regular' | 'bold' | 'italic'); falls back sensibly. */
    public static function fontFile(string $key, string $style): string
    {
        $dir = dirname(__DIR__, 2) . '/includes/pdf/fonts/';
        $key = isset(self::FONTS[$key]) ? $key : 'inter';
        foreach ([$style, 'regular'] as $s) {
            if (is_file($dir . $key . '-' . $s . '.ttf')) return $dir . $key . '-' . $s . '.ttf';
        }
        return $dir . 'inter-regular.ttf';
    }

    public static function fallbackFontFile(): string
    {
        return dirname(__DIR__, 2) . '/includes/pdf/fonts/fallback-sans.ttf';
    }

    // ------------------------------------------------------------- helpers

    public static function color($value, string $fallback): string
    {
        $v = is_string($value) ? strtolower(trim($value)) : '';
        if (preg_match('/^#([0-9a-f]{3})$/', $v, $m)) {
            $v = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }
        return preg_match('/^#[0-9a-f]{6}$/', $v) ? $v : $fallback;
    }

    public static function luminance(string $hex): float
    {
        $h = ltrim(self::color($hex, '#000000'), '#');
        $lin = function (int $c): float { $c /= 255; return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4; };
        return 0.2126 * $lin(hexdec(substr($h, 0, 2))) + 0.7152 * $lin(hexdec(substr($h, 2, 2))) + 0.0722 * $lin(hexdec(substr($h, 4, 2)));
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Near-black or near-white, whichever reads better on $bg. */
    public static function idealInk(string $bg): string
    {
        return self::contrast($bg, '#ffffff') >= self::contrast($bg, '#111111') ? '#ffffff' : '#111111';
    }

    /** $fg if it reads on $bg (ratio >= $min), else the ideal ink. */
    public static function readable(string $fg, string $bg, float $min = 3.0): string
    {
        return self::contrast($fg, $bg) >= $min ? $fg : self::idealInk($bg);
    }

    /** Mix two colours: $t = 0 gives $a, 1 gives $b. */
    public static function mix(string $a, string $b, float $t): string
    {
        $a = ltrim(self::color($a, '#000000'), '#');
        $b = ltrim(self::color($b, '#ffffff'), '#');
        $out = '#';
        for ($i = 0; $i < 3; $i++) {
            $va = hexdec(substr($a, $i * 2, 2));
            $vb = hexdec(substr($b, $i * 2, 2));
            $out .= sprintf('%02x', (int)round($va + ($vb - $va) * $t));
        }
        return $out;
    }

    /**
     * Company CSS for the screen/print renderer. It can never leave the style
     * element: every "<" is removed (CSS needs none, and without it no
     * closing tag can be rebuilt by nesting). @import, legacy script hooks
     * and remote url() are stripped too, so it cannot load code or send page
     * content anywhere; only inline data:image urls survive.
     */
    public static function sanitizeCss(string $css): string
    {
        $css = str_replace('<', '', $css);
        $css = preg_replace('#@import[^;]*;?#i', '', $css) ?? '';
        $css = preg_replace('#expression\s*\(|javascript\s*:|behavior\s*:|-moz-binding#i', '', $css) ?? '';
        $css = preg_replace('#url\s*\(\s*([\'"]?)\s*(?!data:image/(?:png|jpe?g|gif|webp);base64,)[^)]*\)#i', 'none', $css) ?? '';
        return trim($css);
    }

    private static function titleCase(string $s): string
    {
        $s = trim($s);
        return $s === mb_strtoupper($s) ? mb_convert_case(mb_strtolower($s), MB_CASE_TITLE) : $s;
    }

    private static function dig(array $a, array $path, $fallback)
    {
        foreach ($path as $k) {
            if (!is_array($a) || !array_key_exists($k, $a)) return $fallback;
            $a = $a[$k];
        }
        return $a;
    }
}
