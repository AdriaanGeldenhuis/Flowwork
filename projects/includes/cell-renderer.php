<?php
/**
 * Cell Renderer - Handles display of different column types
 * Included from board.php inside the cell TD loop
 */

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
    if ($value): ?>
        <span class="fw-status-badge" style="background: <?= $statusConfig[$value]['color'] ?? '#8b5cf6' ?>;">
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
    if ($value): ?>
        <div class="fw-date-pill">
            <svg width="14" height="14" fill="currentColor">
                <rect x="2" y="3" width="10" height="9" rx="1" stroke="currentColor" stroke-width="1.5" fill="none"/>
                <path d="M2 5h10M5 1v3M9 1v3"/>
            </svg>
            <span><?= date('M j, Y', strtotime($value)) ?></span>
        </div>
    <?php else: ?>
        <button class="fw-cell-empty">+</button>
    <?php endif;

// Number & Formula Cell
elseif ($col['type'] === 'number' || $col['type'] === 'formula'):
    if ($value !== null && $value !== ''): ?>
        <span class="fw-cell-number"><?= htmlspecialchars($value) ?></span>
    <?php else: ?>
        <button class="fw-cell-empty">+</button>
    <?php endif;

// Checkbox Cell
elseif ($col['type'] === 'checkbox'):
    echo $value == '1' ? '<span style="font-size:20px;">✅</span>' : '<span style="font-size:20px;opacity:0.3;">☐</span>';

// Tags Cell
elseif ($col['type'] === 'tags'):
    if ($value):
        $tags = explode(',', $value); ?>
        <div style="display:flex;gap:4px;flex-wrap:wrap;">
            <?php foreach ($tags as $tag): ?>
                <span class="fw-tag"><?= htmlspecialchars(trim($tag)) ?></span>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <button class="fw-cell-empty">+</button>
    <?php endif;

// Link Cell
elseif ($col['type'] === 'link'):
    if ($value): ?>
        <a href="<?= htmlspecialchars($value) ?>" target="_blank" style="color:var(--primary);text-decoration:underline;">🔗 Link</a>
    <?php else: ?>
        <button class="fw-cell-empty">+</button>
    <?php endif;

// Email Cell
elseif ($col['type'] === 'email'):
    if ($value): ?>
        <a href="mailto:<?= htmlspecialchars($value) ?>" style="color:var(--primary);">✉️ <?= htmlspecialchars($value) ?></a>
    <?php else: ?>
        <button class="fw-cell-empty">+</button>
    <?php endif;

// Phone Cell
elseif ($col['type'] === 'phone'):
    if ($value): ?>
        <a href="tel:<?= htmlspecialchars($value) ?>" style="color:var(--primary);">📞 <?= htmlspecialchars($value) ?></a>
    <?php else: ?>
        <button class="fw-cell-empty">+</button>
    <?php endif;

// Progress Cell
elseif ($col['type'] === 'progress'):
    if ($value !== null && $value !== ''): ?>
        <div style="display:flex;align-items:center;gap:8px;">
            <div style="flex:1;height:8px;background:rgba(255,255,255,0.1);border-radius:8px;overflow:hidden;">
                <div style="height:100%;background:var(--primary);width:<?= (int)$value ?>%;"></div>
            </div>
            <span style="font-size:12px;font-weight:600;min-width:40px;"><?= (int)$value ?>%</span>
        </div>
    <?php else: ?>
        <button class="fw-cell-empty">+</button>
    <?php endif;

// Files Cell
elseif ($col['type'] === 'files'):
    if (isset($attachmentsMap[$item['id']]) && !empty($attachmentsMap[$item['id']])): ?>
        <div style="display:flex;gap:4px;align-items:center;">
            <span>📎</span>
            <span style="font-weight:600;"><?= count($attachmentsMap[$item['id']]) ?> file(s)</span>
        </div>
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