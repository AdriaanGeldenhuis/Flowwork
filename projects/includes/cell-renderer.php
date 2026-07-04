<?php
/**
 * Cell Renderer - Handles display of different column types
 * Included from board.php inside the cell TD loop
 */

// Pick a legible text color (black/white) for a given background, so status
// badges meet WCAG contrast instead of always using white. Defined once
// (this file is included per-cell in a loop).
if (!function_exists('fw_readable_text')) {
    function fw_readable_text($hex) {
        $hex = ltrim((string)$hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) < 6) {
            return '#ffffff';
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $lum = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
        return $lum > 0.6 ? '#1a1a1a' : '#ffffff';
    }
}

// Supplier Cell
if ($col['type'] === 'supplier'):
    if ($value):
        $supplier = null;
        foreach ($suppliers as $s) {
            if ($s['id'] == $value) {
                $supplier = $s;
                break;
            }
        }
        if ($supplier): ?>
            <div class="fw-supplier-pill">
                <span class="fw-supplier-icon">🏢</span>
                <span class="fw-supplier-name"><?= htmlspecialchars($supplier['name']) ?></span>
                <?php if ($supplier['preferred']): ?><span class="fw-supplier-badge">⭐</span><?php endif; ?>
            </div>
        <?php else: ?>
            <button class="fw-cell-empty">+</button>
        <?php endif;
    else: ?>
        <button class="fw-cell-empty">+</button>
    <?php endif;

// Status Cell
elseif ($col['type'] === 'status'):
    $__sc = $statusConfig[$value]['color'] ?? '#8b5cf6';
    if ($value): ?>
        <span class="fw-status-badge" style="background: <?= $__sc ?>; color: <?= fw_readable_text($__sc) ?>;">
            <?= strtoupper(htmlspecialchars($value)) ?>
        </span>
    <?php else: ?>
        <button class="fw-cell-empty">+</button>
    <?php endif;

// Priority Cell
elseif ($col['type'] === 'priority'):
    if ($value): ?>
        <button class="fw-priority-pill fw-priority-pill--<?= htmlspecialchars($value) ?>" 
                data-value="<?= htmlspecialchars($value) ?>">
            <span class="fw-priority-dot fw-priority-dot--<?= htmlspecialchars($value) ?>"></span>
            <span class="fw-priority-label"><?= ucfirst(htmlspecialchars($value)) ?></span>
        </button>
    <?php else: ?>
        <button class="fw-cell-empty">+</button>
    <?php endif;

// People Cell
elseif ($col['type'] === 'people'):
    if ($value):
        $user = null;
        foreach ($users as $u) {
            if ($u['id'] == $value) {
                $user = $u;
                break;
            }
        }
        if ($user): ?>
            <div class="fw-user-pill">
                <div class="fw-avatar-sm">
                    <?= strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)) ?>
                </div>
                <span class="fw-user-name"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></span>
            </div>
        <?php else: ?>
            <button class="fw-cell-empty">+</button>
        <?php endif;
    else: ?>
        <button class="fw-cell-empty">+</button>
    <?php endif;

// Date Cell
elseif ($col['type'] === 'date'):
    if ($value):
        $ts = strtotime($value);
        $today = strtotime(date('Y-m-d'));
        $dateClass = 'fw-date-pill';
        if ($ts < $today) {
            $dateClass .= ' fw-date-pill--overdue';
        } elseif ($ts === $today) {
            $dateClass .= ' fw-date-pill--today';
        } elseif ($ts < $today + 86400 * 3) {
            $dateClass .= ' fw-date-pill--soon';
        }
        ?>
        <div class="<?= $dateClass ?>">
            <svg width="14" height="14" fill="currentColor">
                <rect x="2" y="3" width="10" height="9" rx="1" stroke="currentColor" stroke-width="1.5" fill="none"/>
                <path d="M2 5h10M5 1v3M9 1v3"/>
            </svg>
            <span><?= date('M j, Y', $ts) ?></span>
        </div>
    <?php else: ?>
        <button class="fw-cell-empty">+</button>
    <?php endif;

// Timeline Cell
elseif ($col['type'] === 'timeline'):
    $timeline = null;
    if ($value) {
        $decoded = json_decode($value, true);
        if (is_array($decoded) && !empty($decoded['start']) && !empty($decoded['end'])) {
            $timeline = $decoded;
        }
    }
    if ($timeline):
        $startTs = strtotime($timeline['start']);
        $endTs = strtotime($timeline['end']);
        $today = strtotime(date('Y-m-d'));
        $totalDays = max(1, round(($endTs - $startTs) / 86400) + 1);
        $progressPct = 0;
        if ($today <= $startTs) {
            $progressPct = 0;
        } elseif ($today >= $endTs) {
            $progressPct = 100;
        } else {
            $progressPct = round((($today - $startTs) / max(1, ($endTs - $startTs))) * 100);
        }
        $tlClass = 'fw-timeline-pill';
        if ($today > $endTs) $tlClass .= ' fw-timeline-pill--overdue';
        elseif ($today >= $startTs) $tlClass .= ' fw-timeline-pill--active';
        ?>
        <div class="<?= $tlClass ?>">
            <div class="fw-timeline-bar">
                <div class="fw-timeline-bar-fill" style="width: <?= $progressPct ?>%;"></div>
            </div>
            <div class="fw-timeline-label">
                <?= date('M j', $startTs) ?> → <?= date('M j', $endTs) ?>
                <span class="fw-timeline-days"><?= $totalDays ?>d</span>
            </div>
        </div>
    <?php else: ?>
        <button class="fw-cell-empty">+</button>
    <?php endif;

// Number & Formula Cell
elseif ($col['type'] === 'number' || $col['type'] === 'formula'):
    if ($value !== null && $value !== ''):
        $colCfg = !empty($col['config']) ? json_decode($col['config'], true) : [];
        $format = $colCfg['format'] ?? 'decimal';
        $precision = isset($colCfg['precision']) ? (int)$colCfg['precision'] : 2;
        $affix = isset($colCfg['affix']) ? (string)$colCfg['affix'] : '';
        $affixPos = (isset($colCfg['affixPosition']) && $colCfg['affixPosition'] === 'suffix') ? 'suffix' : 'prefix';

        $numericValue = is_numeric($value) ? (float)$value : null;
        if ($numericValue !== null) {
            if ($format === 'percentage') {
                $displayValue = number_format($numericValue, $precision, '.', ',') . '%';
            } else {
                $displayValue = number_format($numericValue, $precision, '.', ',');
            }
        } else {
            $displayValue = htmlspecialchars($value);
        }

        if ($affix !== '') {
            $affixHtml = htmlspecialchars($affix);
            $sep = '<span style="display:inline-block;width:0.25em;"></span>';
            $displayValue = $affixPos === 'prefix' ? $affixHtml . $sep . $displayValue : $displayValue . $sep . $affixHtml;
        }

        $numClass = 'fw-cell-number';
        if ($numericValue !== null && $numericValue < 0) $numClass .= ' fw-cell-number--negative';
        ?>
        <span class="<?= $numClass ?>"><?= $displayValue ?></span>
    <?php else: ?>
        <button class="fw-cell-empty">+</button>
    <?php endif;

// Checkbox Cell
elseif ($col['type'] === 'checkbox'):
    $checked = $value == '1';
    ?>
    <span class="fw-cell-checkbox <?= $checked ? 'fw-cell-checkbox--checked' : '' ?>"><?= $checked ? '✓' : '' ?></span>
    <?php

// Tags Cell
elseif ($col['type'] === 'tags'):
    if ($value):
        $tags = array_filter(array_map('trim', explode(',', $value)));
        // Palette of pleasant colors
        $tagPalette = ['#ef4444','#f97316','#f59e0b','#eab308','#84cc16','#22c55e','#10b981','#14b8a6','#06b6d4','#0ea5e9','#3b82f6','#6366f1','#8b5cf6','#a855f7','#d946ef','#ec4899','#f43f5e'];
        ?>
        <div style="display:flex;gap:4px;flex-wrap:wrap;">
            <?php foreach ($tags as $tag):
                $hash = crc32($tag);
                $color = $tagPalette[$hash % count($tagPalette)];
                ?>
                <span class="fw-tag" style="background:<?= $color ?>;color:<?= fw_readable_text($color) ?>;border:1px solid <?= $color ?>;"><?= htmlspecialchars($tag) ?></span>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <button class="fw-cell-empty">+</button>
    <?php endif;

// Link Cell
elseif ($col['type'] === 'link'):
    if ($value): ?>
        <div style="display:flex;align-items:center;gap:8px;justify-content:space-between;">
            <a href="<?= htmlspecialchars($value) ?>" target="_blank" rel="noopener" style="color:var(--primary);text-decoration:underline;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">🔗 <?= htmlspecialchars(preg_replace('#^https?://#', '', $value)) ?></a>
            <button type="button" class="fw-cell-edit-btn" title="Edit link" style="background:none;border:none;cursor:pointer;opacity:0.5;font-size:12px;padding:2px 4px;">✏️</button>
        </div>
    <?php else: ?>
        <button class="fw-cell-empty">+</button>
    <?php endif;

// Email Cell
elseif ($col['type'] === 'email'):
    if ($value): ?>
        <div style="display:flex;align-items:center;gap:8px;justify-content:space-between;min-width:0;">
            <a href="mailto:<?= htmlspecialchars($value) ?>" title="<?= htmlspecialchars($value) ?>" style="color:var(--primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;">✉️ <?= htmlspecialchars($value) ?></a>
            <button type="button" class="fw-cell-edit-btn" title="Edit email" style="background:none;border:none;cursor:pointer;opacity:0.5;font-size:12px;padding:2px 4px;flex-shrink:0;">✏️</button>
        </div>
    <?php else: ?>
        <button class="fw-cell-empty">+</button>
    <?php endif;

// Phone Cell
elseif ($col['type'] === 'phone'):
    if ($value):
        // Format SA-style: 082 123 4567 or +27 82 123 4567
        $digits = preg_replace('/\D/', '', $value);
        $formatted = $value;
        if (strlen($digits) === 10 && substr($digits, 0, 1) === '0') {
            $formatted = substr($digits, 0, 3) . ' ' . substr($digits, 3, 3) . ' ' . substr($digits, 6);
        } elseif (strlen($digits) === 11 && substr($digits, 0, 2) === '27') {
            $formatted = '+27 ' . substr($digits, 2, 2) . ' ' . substr($digits, 4, 3) . ' ' . substr($digits, 7);
        }
        ?>
        <div style="display:flex;align-items:center;gap:6px;justify-content:space-between;min-width:0;">
            <div style="display:flex;align-items:center;gap:6px;min-width:0;">
                <a href="tel:<?= htmlspecialchars($value) ?>" style="color:var(--primary);white-space:nowrap;">📞 <?= htmlspecialchars($formatted) ?></a>
                <a href="https://wa.me/<?= htmlspecialchars($digits) ?>" target="_blank" rel="noopener" title="WhatsApp" style="text-decoration:none;font-size:14px;">💬</a>
            </div>
            <button type="button" class="fw-cell-edit-btn" title="Edit phone" style="background:none;border:none;cursor:pointer;opacity:0.5;font-size:12px;padding:2px 4px;flex-shrink:0;">✏️</button>
        </div>
    <?php else: ?>
        <button class="fw-cell-empty">+</button>
    <?php endif;

// Progress Cell
elseif ($col['type'] === 'progress'):
    if ($value !== null && $value !== ''):
        $pct = max(0, min(100, (int)$value));
        if ($pct >= 100) $progressColor = '#10b981';      // green - complete
        elseif ($pct >= 70) $progressColor = '#22c55e';   // light green
        elseif ($pct >= 40) $progressColor = '#eab308';   // yellow
        elseif ($pct >= 15) $progressColor = '#f97316';   // orange
        else $progressColor = '#ef4444';                  // red
        ?>
        <div class="fw-progress-wrap">
            <div class="fw-progress-track">
                <div class="fw-progress-fill" style="width:<?= $pct ?>%;background:<?= $progressColor ?>;"></div>
            </div>
            <span class="fw-progress-label" style="color:<?= $progressColor ?>;"><?= $pct ?>%</span>
        </div>
    <?php else: ?>
        <button class="fw-cell-empty">+</button>
    <?php endif;

// Files Cell
elseif ($col['type'] === 'files'):
    if (isset($attachmentsMap[$item['id']]) && !empty($attachmentsMap[$item['id']])):
        $files = $attachmentsMap[$item['id']];
        $names = [];
        foreach ($files as $f) {
            // board.php builds the map with 'file_name' (older callers used other keys)
            $names[] = $f['file_name'] ?? $f['filename'] ?? $f['name'] ?? 'file';
        }
        ?>
        <div class="fw-files-pill" title="<?= htmlspecialchars(implode("\n", $names)) ?>">
            <span>📎</span>
            <span style="font-weight:600;"><?= count($files) ?> file<?= count($files) > 1 ? 's' : '' ?></span>
        </div>
    <?php else: ?>
        <button class="fw-cell-empty">+</button>
    <?php endif;

// Dropdown Cell (with per-option color)
elseif ($col['type'] === 'dropdown'):
    if ($value !== null && $value !== ''):
        $colCfg = !empty($col['config']) ? json_decode($col['config'], true) : [];
        $optionColors = $colCfg['optionColors'] ?? [];
        $palette = ['#ef4444','#f97316','#f59e0b','#eab308','#84cc16','#22c55e','#10b981','#14b8a6','#06b6d4','#0ea5e9','#3b82f6','#6366f1','#8b5cf6','#a855f7','#d946ef','#ec4899'];
        $color = $optionColors[$value] ?? $palette[crc32($value) % count($palette)];
        ?>
        <span class="fw-dropdown-pill" style="background:<?= $color ?>;color:<?= fw_readable_text($color) ?>;border:1px solid <?= $color ?>;"><?= htmlspecialchars($value) ?></span>
    <?php else: ?>
        <button class="fw-cell-empty">+</button>
    <?php endif;

// Long text cell (multi-line)
elseif ($col['type'] === 'longtext'):
    if ($value !== null && $value !== ''): ?>
        <div class="fw-cell-longtext" title="<?= htmlspecialchars($value) ?>"><?= nl2br(htmlspecialchars($value)) ?></div>
    <?php else: ?>
        <button class="fw-cell-empty">+</button>
    <?php endif;

// Default Text Cell
else:
    if ($value !== null && $value !== ''):
        echo htmlspecialchars($value);
    else: ?>
        <button class="fw-cell-empty">+</button>
    <?php endif;
endif;
?>