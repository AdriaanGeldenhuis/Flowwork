<?php
/**
 * Branding — single source of truth for Quote/Invoice/Credit-note document
 * styling (colours, text, fonts).
 *
 * Every place that renders a document (invoice_view, quote_view,
 * credit_note_view, the printable page generate_pdf, the public portal
 * p/quote and the binary PDF writer) used to re-implement the same colour
 * sanitising, font map and :root CSS block by hand — and they had drifted
 * apart. This class centralises that logic so a document looks identical on
 * screen, on the printed page, in the downloaded PDF and on the public link.
 *
 * Pure helper: no DB access, no session, no globals. Feed it a company/
 * document row (already fetched) and it returns fully-resolved, sanitised
 * branding values plus the CSS to emit.
 */
final class Branding
{
    /** Web/CSS font stacks keyed by the qi_font_family setting. */
    public const FONT_STACKS = [
        'system-ui'  => "system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif",
        'montserrat' => "'Montserrat', system-ui, sans-serif",
        'helvetica'  => "'Helvetica Neue', Helvetica, Arial, sans-serif",
        'georgia'    => "Georgia, 'Times New Roman', Times, serif",
        'inter'      => "'Inter', system-ui, sans-serif",
    ];

    /**
     * PDF base-14 fonts keyed by the qi_font_family setting, as
     * [regular, bold]. The binary PDF writer can only embed the 14 standard
     * fonts, so the serif choice maps to Times and everything else to
     * Helvetica — at least the serif/sans intent of the setting is honoured.
     */
    public const PDF_FONTS = [
        'system-ui'  => ['Helvetica', 'Helvetica-Bold'],
        'montserrat' => ['Helvetica', 'Helvetica-Bold'],
        'helvetica'  => ['Helvetica', 'Helvetica-Bold'],
        'inter'      => ['Helvetica', 'Helvetica-Bold'],
        'georgia'    => ['Times-Roman', 'Times-Bold'],
    ];

    public const TEMPLATES = ['modern', 'classic', 'minimal', 'bold', 'corporate'];

    private const DEFAULT_PRIMARY   = '#fbbf24';
    private const DEFAULT_SECONDARY = '#f59e0b';

    /**
     * Resolve every branding value for a document.
     *
     * @param array  $row  Joined document + company row.
     * @param string $type 'invoice' | 'quote' | 'credit_note'
     * @return array Fully-resolved, sanitised branding values.
     */
    public static function resolve(array $row, string $type): array
    {
        $primary   = self::sanitizeColor($row['primary_color'] ?? null, self::DEFAULT_PRIMARY);
        $secondary = self::sanitizeColor($row['secondary_color'] ?? null, self::DEFAULT_SECONDARY);
        $heading   = self::sanitizeColor($row['qi_heading_color'] ?? null, $primary);

        // Document background: only treated as "set" when the user chose one,
        // so the dark-theme fallback in templates-pro.css keeps working.
        $bgExplicit = !empty($row['qi_bg_color']);
        $bg         = $bgExplicit ? self::sanitizeColor($row['qi_bg_color'], '#ffffff') : '#ffffff';
        $bgIsLight  = self::isLight($bg);

        // Body text: honour an explicit choice, else pick a tone that stays
        // readable on the chosen background.
        $textBody = !empty($row['qi_text_color'])
            ? self::sanitizeColor($row['qi_text_color'], '#374151')
            : ($bgIsLight ? '#374151' : '#d1d5db');

        // Strong/heading-weight body text (company name, totals, grand total)
        // and muted captions follow the background so they never vanish on a
        // dark document background.
        $textStrong = $bgIsLight ? '#1f2937' : '#f9fafb';
        $textMuted  = $bgIsLight ? '#6b7280' : '#9ca3af';

        // Table-header text: respect an explicit colour that reads well on the
        // primary, otherwise auto-pick black/white so the header is legible.
        // (Fixes the default white-on-amber, which fails contrast.)
        $theadText = self::sanitizeColor($row['qi_table_header_text'] ?? null, '#ffffff');
        if (self::contrastRatio($theadText, $primary) < 3.0) {
            $theadText = self::idealInk($primary);
        }

        $fontKey   = (string)($row['qi_font_family'] ?? 'system-ui');
        $fontStack = self::FONT_STACKS[$fontKey] ?? self::FONT_STACKS['system-ui'];
        $pdfFont   = self::PDF_FONTS[$fontKey]   ?? self::PDF_FONTS['system-ui'];

        $template = (string)($row['qi_template'] ?? 'modern');
        if (!in_array($template, self::TEMPLATES, true)) {
            $template = 'modern';
        }

        $logoPosition = (string)($row['qi_logo_position'] ?? 'left');
        if (!in_array($logoPosition, ['left', 'center', 'right'], true)) {
            $logoPosition = 'left';
        }

        // Per-type title + footer (quotes were previously getting the invoice
        // footer in some renderers).
        if ($type === 'quote') {
            $title  = (string)($row['qi_quote_title'] ?? '') ?: 'QUOTATION';
            $footer = (string)($row['quote_footer_text'] ?? '');
        } elseif ($type === 'credit_note') {
            $title  = 'CREDIT NOTE';
            $footer = (string)($row['invoice_footer_text'] ?? '');
        } else {
            $title  = (string)($row['qi_invoice_title'] ?? '') ?: 'INVOICE';
            $footer = (string)($row['invoice_footer_text'] ?? '');
        }

        return [
            'type'          => $type,
            'primary'       => $primary,
            'secondary'     => $secondary,
            'heading'       => $heading,
            'text_body'     => $textBody,
            'text_strong'   => $textStrong,
            'text_muted'    => $textMuted,
            'bg'            => $bg,
            'bg_explicit'   => $bgExplicit,
            'thead_text'    => $theadText,
            'border_radius' => max(0, min(24, (int)($row['qi_border_radius'] ?? 8))),
            'logo_size'     => max(80, min(400, (int)($row['qi_logo_size'] ?? 200))),
            'logo_position' => $logoPosition,
            'template'      => $template,
            'font_key'      => $fontKey,
            'font_stack'    => $fontStack,
            'pdf_font'      => $pdfFont,
            'custom_css'    => self::sanitizeCustomCss((string)($row['qi_custom_css'] ?? '')),
            'title'         => $title,
            'footer'        => $footer,
            'show'          => [
                'address' => self::toggle($row['qi_show_company_address'] ?? null),
                'phone'   => self::toggle($row['qi_show_company_phone']   ?? null),
                'email'   => self::toggle($row['qi_show_company_email']   ?? null),
                'website' => self::toggle($row['qi_show_company_website'] ?? null),
                'vat'     => self::toggle($row['qi_show_vat_number']      ?? null),
                'tax'     => self::toggle($row['qi_show_tax_number']      ?? null),
                'reg'     => self::toggle($row['qi_show_reg_number']      ?? null),
                'payment' => self::toggle($row['qi_show_payment_details'] ?? null),
            ],
        ];
    }

    /** <link> tags that load the web fonts referenced by FONT_STACKS. */
    public static function fontHeadLinks(): string
    {
        return
            '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n" .
            '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n" .
            '<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">';
    }

    /**
     * The CSS to drop inside the document <style> block: the :root custom
     * properties consumed by templates-pro.css plus the font-family rule.
     * Does NOT include the company's custom CSS — emit that separately,
     * last, via customCss().
     */
    public static function documentStyle(array $b): string
    {
        $css  = ":root {\n";
        $css .= "    --accent-qi: {$b['primary']};\n";
        $css .= "    --accent-qi-secondary: {$b['secondary']};\n";
        $css .= "    --doc-font: {$b['font_stack']};\n";
        $css .= "    --qi-heading: {$b['heading']};\n";
        $css .= "    --qi-text: {$b['text_body']};\n";
        $css .= "    --qi-text-dk: {$b['text_strong']};\n";
        $css .= "    --qi-muted: {$b['text_muted']};\n";
        $css .= "    --qi-th-text: {$b['thead_text']};\n";
        // Two-colour brand header for the line-items table (degrades to a flat
        // fill when primary == secondary). This is what makes the secondary
        // colour actually do something on every template.
        $css .= "    --qi-thead-bg: linear-gradient(135deg, {$b['primary']}, {$b['secondary']});\n";
        $css .= "    --qi-border-radius: {$b['border_radius']}px;\n";
        $css .= "    --qi-logo-max-w: {$b['logo_size']}px;\n";
        if ($b['bg_explicit']) {
            $css .= "    --qi-doc-bg: {$b['bg']};\n";
        }
        $css .= "}\n";
        $css .= ".fw-qi__document, .fw-qi__document * { font-family: {$b['font_stack']} !important; }\n";

        return $css;
    }

    /**
     * Company custom CSS, sanitised so it cannot break out of the <style>
     * element. Returned ready to echo (already empty-safe).
     */
    public static function customCss(array $b): string
    {
        return $b['custom_css'];
    }

    public static function sanitizeCustomCss(string $css): string
    {
        // Strip anything that could close the <style> tag and inject markup.
        return preg_replace('#</\s*style\s*>?#i', '', $css) ?? '';
    }

    // ===== colour utilities =====

    public static function sanitizeColor(?string $color, string $fallback): string
    {
        $color = trim((string)$color);
        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) ? $color : $fallback;
    }

    /** Normalise a hex colour to [r, g, b] ints (0-255). */
    public static function toRgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) < 6 || !ctype_xdigit(substr($hex, 0, 6))) {
            $hex = 'fbbf24';
        }
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    /** Relative luminance (WCAG), 0 (black) .. 1 (white). */
    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::toRgb($hex);
        $lin = static function (int $c): float {
            $cs = $c / 255;
            return $cs <= 0.03928 ? $cs / 12.92 : pow(($cs + 0.055) / 1.055, 2.4);
        };
        return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
    }

    public static function isLight(string $hex): bool
    {
        return self::luminance($hex) >= 0.5;
    }

    /** WCAG contrast ratio between two colours (1 .. 21). */
    public static function contrastRatio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        $hi = max($la, $lb);
        $lo = min($la, $lb);
        return ($hi + 0.05) / ($lo + 0.05);
    }

    /** Best-contrast ink (near-black or near-white) for a given background. */
    public static function idealInk(string $bg): string
    {
        return self::isLight($bg) ? '#1f2937' : '#ffffff';
    }

    private static function toggle($value): int
    {
        // Display toggles default to ON when the column is null/missing.
        return $value === null ? 1 : ((int)$value ? 1 : 0);
    }
}
