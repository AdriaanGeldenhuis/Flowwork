<?php
// /admin/audit.php — Audit trail: everything that changed in the company and
// who did it, in plain words, grouped by day. Filter by person, kind, words
// and dates; the CSV export takes the same filters (AdminAudit::where).
require_once __DIR__ . '/lib/AdminBoot.php';
require_once __DIR__ . '/lib/AdminAudit.php';

$f = AdminAudit::filters($_GET);
[$where, $params] = AdminAudit::where($ADMIN_COMPANY_ID, $f);
$perPage = 60;
$pageNo = max(1, (int)($_GET['page'] ?? 1));
$st = $DB->prepare("SELECT COUNT(*) FROM audit_log al LEFT JOIN users u ON u.id = al.user_id WHERE $where");
$st->execute($params);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$pageNo = min($pageNo, $pages);
$st = $DB->prepare("SELECT al.id, al.action, al.details, al.ip, al.entity_type, COALESCE(al.timestamp, al.created_at) AS at, al.user_id,
                           CONCAT_WS(' ', u.first_name, u.last_name) AS who, u.email
                      FROM audit_log al LEFT JOIN users u ON u.id = al.user_id
                     WHERE $where ORDER BY al.id DESC LIMIT $perPage OFFSET " . (($pageNo - 1) * $perPage));
$st->execute($params);
$logs = $st->fetchAll(PDO::FETCH_ASSOC);

$st = $DB->prepare("SELECT u.id, CONCAT_WS(' ', u.first_name, u.last_name) AS name FROM user_companies uc JOIN users u ON u.id = uc.user_id WHERE uc.company_id = ? ORDER BY u.first_name, u.last_name");
$st->execute([$ADMIN_COMPANY_ID]);
$users = $st->fetchAll(PDO::FETCH_ASSOC);

$qs = fn(array $over = []) => http_build_query(array_filter(array_merge(['user_id' => $f['user'] ?: null, 'kind' => $f['kind'] ?: null, 'q' => $f['q'] ?: null, 'from' => $f['from'] ?: null, 'to' => $f['to'] ?: null], $over), fn($v) => $v !== null && $v !== ''));
$filtered = $f['user'] || $f['kind'] || $f['q'] !== '' || $f['from'] || $f['to'];
$dayLabel = function (string $at): string {
    $d = date('Y-m-d', strtotime($at));
    if ($d === date('Y-m-d')) return 'Today';
    if ($d === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
    return date(date('Y', strtotime($at)) === date('Y') ? 'l j F' : 'l j F Y', strtotime($at));
};

$page = 'audit';
$pageTitle = 'Audit trail';
include __DIR__ . '/partials/head.php';
?>
<header class="fwa-head">
    <div class="fwa-head__text">
        <p class="fwa-eyebrow">Account <i>◆</i> Audit trail</p>
        <h1>Audit <em>trail</em></h1>
        <p>Every change in <?= $e($companyName) ?> — settings, people, money and more — with who made it and when.</p>
    </div>
    <div class="fwa-head__actions">
        <a class="btn" href="/admin/api/export.php?<?= $e($qs(['type' => 'audit'])) ?>" download><svg class="i"><use href="#i-download"/></svg>Export<?= $filtered ? ' these' : '' ?> to CSV</a>
    </div>
</header>

<section class="fwa-card">
    <div class="fwa-tools" style="padding-bottom:4px">
        <div class="fwa-chips">
            <a class="fwa-chip<?= $f['kind'] === '' ? ' is-on' : '' ?>" href="?<?= $e($qs(['kind' => null, 'page' => null])) ?>">Everything</a>
            <?php foreach (AdminAudit::KINDS as $k => [$label]): ?>
                <a class="fwa-chip<?= $f['kind'] === $k ? ' is-on' : '' ?>" href="?<?= $e($qs(['kind' => $k, 'page' => null])) ?>"><?= $e($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <form class="fwa-filters" method="get" action="/admin/audit.php">
        <?php if ($f['kind']): ?><input type="hidden" name="kind" value="<?= $e($f['kind']) ?>"><?php endif; ?>
        <div class="field"><label class="label" for="aq">Words</label><input class="input" id="aq" type="search" name="q" value="<?= $e($f['q']) ?>" placeholder="e.g. invoice, VAT, an IP address"></div>
        <div class="field"><label class="label" for="au">Person</label><select class="select" id="au" name="user_id"><option value="">Anyone</option>
            <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $f['user'] === (int)$u['id'] ? ' selected' : '' ?>><?= $e(trim((string)$u['name'])) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label class="label" for="af">From</label><input class="input" id="af" type="date" name="from" value="<?= $e($f['from']) ?>"></div>
        <div class="field"><label class="label" for="at">To</label><input class="input" id="at" type="date" name="to" value="<?= $e($f['to']) ?>"></div>
        <div class="field" style="flex-direction:row;gap:8px"><button class="btn btn--primary" type="submit"><svg class="i"><use href="#i-filter"/></svg>Filter</button>
            <?php if ($filtered): ?><a class="btn btn--ghost" href="/admin/audit.php">Clear</a><?php endif; ?></div>
    </form>

    <?php if (!$logs): ?>
        <div class="fwa-table__empty"><b><?= $filtered ? 'Nothing matches' : 'Nothing recorded yet' ?></b><?= $filtered ? 'Try fewer filters.' : 'Changes appear here as people work.' ?></div>
    <?php else: ?>
        <div class="fwa-table-wrap"><table class="fwa-table">
            <thead><tr><th style="width:84px">Time</th><th>Who</th><th>What happened</th><th class="hide-narrow">From</th></tr></thead>
            <tbody>
            <?php $day = null; foreach ($logs as $l):
                $d = $dayLabel((string)$l['at']);
                [$label, $tone] = AdminKit::actionLabel((string)$l['action']);
                $who = trim((string)$l['who']) ?: ($l['user_id'] ? 'Former member' : 'Flowwork');
                $detail = AdminKit::detailText($l['details']); ?>
                <?php if ($d !== $day): $day = $d; ?><tr class="fwa-day"><td colspan="4"><?= $e($d) ?></td></tr><?php endif; ?>
                <tr>
                    <td class="fwa-num" title="<?= $e(AdminKit::date($l['at'], true)) ?>"><?= $e(date('H:i', strtotime((string)$l['at']))) ?></td>
                    <td><div class="fwa-who"><span class="fwa-ava fwa-ava--sm fwa-ava--round" style="--h:<?= AdminKit::hue($who) ?>"><?= $e(AdminKit::initials($who)) ?></span>
                        <span class="fwa-who__text"><b><?= $e($who) ?></b><?php if ($l['email']): ?><small><?= $e($l['email']) ?></small><?php endif; ?></span></div></td>
                    <td><span class="fwa-act" style="--c:var(--<?= ['success' => 'success', 'danger' => 'danger', 'warning' => 'warning', 'info' => 'info', 'gold' => 'gold'][$tone] ?? 'ink-4' ?>)"><span class="fwa-act__dot"></span><?= $e($label) ?></span>
                        <?php if ($detail !== ''): ?><div class="fwa-details"><?= $e($detail) ?></div><?php endif; ?>
                        <?php if (AdminKit::actionLabel((string)$l['action'])[1] === 'muted' && !in_array($l['action'], ['user_logout', 'password_reset_requested', 'company_switch', 'audit_pruned'], true)): ?><small class="fwa-muted fwa-mono"><?= $e($l['action']) ?></small><?php endif; ?></td>
                    <td class="hide-narrow"><span class="fwa-mono fwa-muted"><?= $e($l['ip'] ?: '—') ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <div class="fwa-table-foot">
            <span><?= number_format($total, 0, '.', ' ') ?> entr<?= $total === 1 ? 'y' : 'ies' ?><?= $pages > 1 ? ' · page ' . $pageNo . ' of ' . $pages : '' ?></span>
            <span class="fwa-grow"></span>
            <?php if ($pageNo > 1): ?><a class="btn btn--sm" href="?<?= $e($qs(['page' => $pageNo - 1])) ?>"><svg class="i"><use href="#i-chev-left"/></svg>Newer</a><?php endif; ?>
            <?php if ($pageNo < $pages): ?><a class="btn btn--sm" href="?<?= $e($qs(['page' => $pageNo + 1])) ?>">Older<svg class="i"><use href="#i-chev-right"/></svg></a><?php endif; ?>
        </div>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/partials/foot.php'; ?>
