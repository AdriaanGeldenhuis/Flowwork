<?php
// /payroll/index.php — Payroll at a glance: the run that is due or in
// progress, what the last pay day cost, what is owed to SARS and by when,
// what needs fixing before the next run, and the cost of pay over a year.
require_once __DIR__ . '/lib/PayBoot.php';

$C = $PAY_COMPANY_ID;
$money = fn(int $c) => PayKit::money($c);

$people = PayEmployees::list($DB, $C, ['status' => 'active'])['rows'];
$active = count($people);
$st = $DB->prepare("SELECT * FROM pay_runs WHERE company_id = ? AND status IN ('draft','calculated','review','approved') ORDER BY pay_date, id LIMIT 1");
$st->execute([$C]);
$open = $st->fetch(PDO::FETCH_ASSOC) ?: null;
$openRun = $open ? PayRuns::get($DB, $C, (int)$open['id']) : null;
$suggest = PayKit::suggest($DB, $C, $PAY_SETTINGS['default_frequency'], $PAY_SETTINGS);
$st = $DB->prepare("SELECT r.id, r.name, r.pay_date, r.status, SUM(x.net_cents) net, SUM(x.employer_cost_cents) cost, COUNT(x.id) people
                      FROM pay_runs r JOIN pay_run_employees x ON x.run_id = r.id WHERE r.company_id = ? AND r.status IN ('locked','posted')
                     GROUP BY r.id ORDER BY r.pay_date DESC, r.id DESC LIMIT 1");
$st->execute([$C]);
$last = $st->fetch(PDO::FETCH_ASSOC) ?: null;
$sarsMonth = $last ? substr($last['pay_date'], 0, 7) : date('Y-m');
$emp201 = PayReports::emp201($DB, $C, $sarsMonth);

// Cost of pay, month by month, for the last 12 months
$st = $DB->prepare("SELECT DATE_FORMAT(r.pay_date, '%Y-%m') ym, SUM(x.employer_cost_cents) cost, SUM(x.net_cents) net FROM pay_runs r JOIN pay_run_employees x ON x.run_id = r.id
                     WHERE r.company_id = ? AND r.status IN ('locked','posted') AND r.pay_date >= ? GROUP BY ym");
$st->execute([$C, date('Y-m-01', strtotime('-11 months'))]);
$byMonth = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $byMonth[$r['ym']] = (int)$r['cost'];
$bars = [];
for ($i = 11; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
    $bars[] = ['ym' => $ym, 'label' => date('M', strtotime($ym . '-01')), 'v' => $byMonth[$ym] ?? 0];
}
$maxBar = max(1, max(array_column($bars, 'v')));

// What needs attention
$attn = [];
$miss = fn(string $what) => array_values(array_filter($people, fn($p) => in_array($what, $p['missing'], true)));
if ($x = $miss('tax number')) $attn[] = ['i-file-text', count($x) . ' ' . (count($x) === 1 ? 'person has' : 'people have') . ' no tax number', 'SARS needs it on every tax certificate: ' . implode(', ', array_slice(array_column($x, 'name'), 0, 3)) . (count($x) > 3 ? '…' : '') . '.', '/payroll/employees.php?filter=missing'];
if ($x = $miss('bank details')) $attn[] = ['if-bank', count($x) . ' ' . (count($x) === 1 ? 'person has' : 'people have') . ' no bank details', 'They are left out of the bank payments file.', '/payroll/employees.php?filter=missing'];
if ($x = $miss('date of birth')) $attn[] = ['i-calendar', count($x) . ' ' . (count($x) === 1 ? 'person has' : 'people have') . ' no date of birth', 'It decides the extra tax rebates from age 65. An SA ID number fills it in.', '/payroll/employees.php?filter=missing'];
if ($PAY_SETTINGS['paye_reference'] === '') $attn[] = ['ip-sars', 'Add your PAYE reference number', 'It is printed on payslips and needed for EMP201 and EMP501.', '/payroll/settings.php#set-paye_reference'];
if (!$PAY_SETTINGS['bank_account_id']) $attn[] = ['if-ledger', 'Choose the bank account salaries are paid from', 'Finance needs it to put pay runs in the books.', '/payroll/settings.php#set-bank_account_id'];
$st = $DB->prepare("SELECT COUNT(*) FROM pay_runs WHERE company_id = ? AND status = 'locked'");
$st->execute([$C]);
if ($n = (int)$st->fetchColumn()) $attn[] = ['if-post', $n . ' finalised ' . ($n === 1 ? 'run is' : 'runs are') . ' not in the books yet', 'Post ' . ($n === 1 ? 'it' : 'them') . ' so Finance shows the wages, PAYE, UIF and SDL.', '/payroll/runs.php?stage=final'];
$st = $DB->prepare("SELECT COUNT(*), MAX(r.id) FROM pay_run_employees x JOIN pay_runs r ON r.id = x.run_id WHERE r.company_id = ? AND x.payslip_email_error IS NOT NULL AND r.pay_date >= ?");
$st->execute([$C, date('Y-m-d', strtotime('-60 days'))]);
[$n, $failedRun] = $st->fetch(PDO::FETCH_NUM);
if ($n = (int)$n) $attn[] = ['i-mail', $n . ' payslip' . ($n === 1 ? '' : 's') . ' could not be emailed', 'Open the run to see why and send again.', '/payroll/run_view.php?id=' . (int)$failedRun];
$tax = PaySettings::taxTables($DB, $C);
if (!$tax['has_next'] && (int)date('n') >= 2 && (int)date('n') <= 3) $attn[] = ['ip-calc', 'The ' . $tax['next_label'] . ' tax tables are not in yet', 'Runs paid from 1 March need them. Add SARS’s new figures under Settings → Tax tables if they are out.', '/payroll/settings.php#tax'];

$st = $DB->prepare("SELECT r.id, r.run_number, r.name, r.pay_date, r.status, COUNT(x.id) people, COALESCE(SUM(x.net_cents),0) net FROM pay_runs r LEFT JOIN pay_run_employees x ON x.run_id = r.id
                     WHERE r.company_id = ? GROUP BY r.id ORDER BY r.pay_date DESC, r.id DESC LIMIT 5");
$st->execute([$C]);
$recent = $st->fetchAll(PDO::FETCH_ASSOC);
$tones = ['prepare' => 'info', 'approved' => 'gold', 'final' => 'accent', 'booked' => 'success'];

$page = 'index';
$pageTitle = 'Overview';
include __DIR__ . '/partials/head.php';

$stagesHtml = function (?array $run) use ($e): string {
    $cur = $run ? $run['stage'] : null;
    $order = ['prepare', 'approved', 'final', 'booked'];
    $idx = $cur ? array_search($cur, $order, true) : -1;
    $steps = [['Prepare', 'Add hours, overtime and bonuses'], ['Approve', 'Check and freeze the figures'], ['Finalise', 'Payslips and the bank file'], ['In the books', 'Wages, PAYE, UIF, SDL']];
    $h = '<div class="fwp-stages" role="list">';
    foreach ($steps as $i => [$t, $s]) {
        $cls = $i < $idx || ($i === $idx && $cur === 'booked') ? 'is-done' : ($i === $idx ? 'is-current' : '');
        $h .= '<div class="fwp-stage ' . $cls . '" role="listitem"><span class="fwp-stage__dot">' . ($cls === 'is-done' ? '<svg class="i"><use href="#i-check"/></svg>' : ($i + 1)) . '</span><b>' . $e($t) . '</b><small>' . $e($s) . '</small></div>';
    }
    return $h . '</div>';
};
?>
<header class="fwp-head">
    <div class="fwp-head__text">
        <p class="fwp-eyebrow">Payroll <i>◆</i> <?= $e(PayTax::taxYearLabel(date('Y-m-d'))) ?> tax year</p>
        <h1>Payroll</h1>
        <p><?= $active ?> <?= $active === 1 ? 'person is' : 'people are' ?> on the payroll<?php if ($last): ?> · the last pay day, <b><?= $e(PayKit::date($last['pay_date'])) ?></b>, paid <b><?= $e($money((int)$last['net'])) ?></b><?php endif; ?>.</p>
    </div>
    <div class="fwp-head__actions">
        <a class="btn" href="/payroll/employees.php?new=1"><svg class="i"><use href="#i-plus"/></svg>Add employee</a>
        <a class="btn btn--primary" href="/payroll/runs.php?new=1"><svg class="i"><use href="#ip-run"/></svg>New pay run</a>
    </div>
</header>

<?php if ($active === 0 && !$recent): ?>
<section class="fwp-card">
    <div class="fwp-card__head"><span class="fwp-card__icon"><svg class="i"><use href="#ip-seal"/></svg></span>
        <div class="fwp-card__title"><h3>Set up payroll in three steps</h3><p>Flowwork works out PAYE, UIF and SDL with SARS’s current tables, makes payslips and puts every run in the books.</p></div></div>
    <div class="fwp-steps">
        <a class="fwp-step" href="/payroll/settings.php"><span class="fwp-step__dot">1</span><span class="fwp-step__text"><b>Check the pay day and your PAYE reference</b><small>Settings — the 25th by default, paid the Friday before when it falls on a weekend.</small></span><svg class="i"><use href="#i-chev-right"/></svg></a>
        <a class="fwp-step" href="/payroll/employees.php?new=1"><span class="fwp-step__dot">2</span><span class="fwp-step__text"><b>Add your employees</b><small>Salary or hourly rate, tax number and bank details.</small></span><svg class="i"><use href="#i-chev-right"/></svg></a>
        <a class="fwp-step" href="/payroll/runs.php?new=1"><span class="fwp-step__dot">3</span><span class="fwp-step__text"><b>Start your first pay run</b><small>Everyone is worked out at once; add overtime or a bonus, approve and finalise.</small></span><svg class="i"><use href="#i-chev-right"/></svg></a>
    </div>
</section>
<?php else: ?>

<section class="fwp-card">
    <div class="fwp-next">
        <span class="fwp-next__icon"><svg class="i"><use href="#<?= $openRun ? 'ip-run' : 'i-calendar' ?>"/></svg></span>
        <div class="fwp-next__text">
            <?php if ($openRun): ?>
                <p class="fwp-eyebrow"><?= $e($openRun['stage_label']) ?> <i>◆</i> paid <?= $e(PayKit::date($openRun['pay_date'])) ?></p>
                <h3><?= $e($openRun['name']) ?></h3>
                <p><?= count($openRun['people']) ?> <?= count($openRun['people']) === 1 ? 'person' : 'people' ?> · net pay <b><?= $e($money($openRun['totals']['net'])) ?></b> · cost to company <?= $e($money($openRun['totals']['cost'])) ?><?php
                    $w = array_sum(array_map(fn($p) => count(array_intersect($p['warnings'], ['negative_net', 'no_hours'])), $openRun['people']));
                    if ($w) echo ' · <span class="fwp-flag fwp-flag--danger">' . $w . ' to sort out</span>'; ?></p>
            <?php else: ?>
                <p class="fwp-eyebrow">Next <i>◆</i> <?= $e(PayKit::FREQ[$suggest['frequency']]) ?></p>
                <h3>Start the <?= $e($suggest['name']) ?> run</h3>
                <p><?= $e(PayKit::period($suggest['period_start'], $suggest['period_end'])) ?>, paid <b><?= $e(date('l j F', strtotime($suggest['pay_date']))) ?></b>.</p>
            <?php endif; ?>
        </div>
        <?php if ($openRun): ?>
            <a class="btn btn--primary" href="/payroll/run_view.php?id=<?= (int)$openRun['id'] ?>">Continue<svg class="i"><use href="#i-chev-right"/></svg></a>
        <?php else: ?>
            <a class="btn btn--primary" href="/payroll/runs.php?new=1">Start pay run<svg class="i"><use href="#i-chev-right"/></svg></a>
        <?php endif; ?>
        <?= $stagesHtml($openRun) ?>
    </div>
</section>

<div class="fwp-stats" style="margin-top:16px">
    <a class="fwp-stat" href="/payroll/employees.php" style="--i:0"><span class="fwp-stat__label">On the payroll</span><span class="fwp-stat__value"><?= $active ?></span>
        <span class="fwp-stat__sub"><?= count(array_filter($people, fn($p) => $p['basis'] === 'hourly')) ?> paid by the hour</span></a>
    <a class="fwp-stat" href="<?= $last ? '/payroll/run_view.php?id=' . (int)$last['id'] : '/payroll/runs.php' ?>" style="--i:1"><span class="fwp-stat__label">Last net pay</span>
        <span class="fwp-stat__value fwp-num"><?= $last ? $e($money((int)$last['net'])) : '—' ?></span><span class="fwp-stat__sub"><?= $last ? $e($last['name']) : 'No finalised run yet' ?></span></a>
    <div class="fwp-stat" style="--i:2"><span class="fwp-stat__label">Cost to company</span><span class="fwp-stat__value fwp-num"><?= $last ? $e($money((int)$last['cost'])) : '—' ?></span>
        <span class="fwp-stat__sub">Pay plus employer UIF and SDL</span></div>
    <a class="fwp-stat<?= $emp201['overdue'] ? ' is-alert' : '' ?>" href="/payroll/reports.php?month=<?= $e($sarsMonth) ?>" style="--i:3"><span class="fwp-stat__label">Owed to SARS · <?= $e(date('M', strtotime($sarsMonth . '-01'))) ?></span>
        <span class="fwp-stat__value fwp-num"><?= $e($money($emp201['totals']['total'])) ?></span><span class="fwp-stat__sub">EMP201 due <?= $e(PayKit::date($emp201['due'], false)) ?></span></a>
</div>

<div class="fwp-cols" style="margin-top:16px">
    <div>
        <section class="fwp-card">
            <div class="fwp-card__head"><span class="fwp-card__icon"><svg class="i"><use href="#i-chart"/></svg></span>
                <div class="fwp-card__title"><h3>Cost of pay</h3><p>Cost to company of finalised runs, by pay month.</p></div></div>
            <?php if (array_sum(array_column($bars, 'v')) === 0): ?>
                <div class="fwp-bars__empty">The first finalised run will show here.</div>
            <?php else: ?>
                <div class="fwp-bars" role="img" aria-label="Cost of pay for the last 12 months">
                    <?php foreach ($bars as $b): ?>
                        <div class="fwp-bar"><i style="--h:<?= max(3, round($b['v'] / $maxBar * 130)) ?>px" data-v="<?= $e($b['label'] . ': ' . $money($b['v'])) ?>"></i><small><?= $e($b['label']) ?></small></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="fwp-card__body"></div>
        </section>
        <section class="fwp-card">
            <div class="fwp-card__head"><span class="fwp-card__icon"><svg class="i"><use href="#ip-run"/></svg></span>
                <div class="fwp-card__title"><h3>Recent pay runs</h3></div><a class="fwp-mini" href="/payroll/runs.php">All runs</a></div>
            <div class="fwp-card__body" style="padding-top:0">
                <?php if (!$recent): ?><p class="fwp-muted">No pay runs yet.</p><?php endif; ?>
                <ul class="fwp-list">
                    <?php foreach ($recent as $r): $sg = PayKit::stage($r['status']); ?>
                        <li><a class="fwp-list__text" href="/payroll/run_view.php?id=<?= (int)$r['id'] ?>"><b><?= $e($r['name']) ?></b><small><?= $e($r['run_number']) ?> · paid <?= $e(PayKit::date($r['pay_date'])) ?> · <?= (int)$r['people'] ?> <?= (int)$r['people'] === 1 ? 'person' : 'people' ?></small></a>
                            <span class="fwp-money"><?= $e($money((int)$r['net'])) ?></span><span class="fwp-pill" data-tone="<?= $tones[$sg] ?>"><?= $e(PayKit::STAGE_LABEL[$sg]) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    </div>
    <div>
        <section class="fwp-card">
            <div class="fwp-card__head"><span class="fwp-card__icon"><svg class="i"><use href="#i-alert"/></svg></span>
                <div class="fwp-card__title"><h3>Needs attention</h3><p><?= $attn ? 'Sort these out before the next pay day.' : 'Everything looks ready.' ?></p></div></div>
            <div class="fwp-steps">
                <?php foreach ($attn as [$icon, $title, $sub, $href]): ?>
                    <a class="fwp-step" href="<?= $e($href) ?>"><span class="fwp-step__dot"><svg class="i"><use href="#<?= $e($icon) ?>"/></svg></span>
                        <span class="fwp-step__text"><b><?= $e($title) ?></b><small><?= $e($sub) ?></small></span><svg class="i"><use href="#i-chev-right"/></svg></a>
                <?php endforeach; ?>
                <?php if (!$attn): ?>
                    <div class="fwp-step is-done"><span class="fwp-step__dot"><svg class="i"><use href="#i-check"/></svg></span><span class="fwp-step__text"><b>Nothing to fix</b><small>Tax numbers, bank details and settings are complete.</small></span></div>
                <?php endif; ?>
            </div>
            <div class="fwp-card__body"></div>
        </section>
        <section class="fwp-card">
            <div class="fwp-card__head"><span class="fwp-card__icon"><svg class="i"><use href="#ip-sars"/></svg></span>
                <div class="fwp-card__title"><h3>SARS · <?= $e($emp201['label']) ?></h3><p>From finalised runs paid in <?= $e(date('F', strtotime($sarsMonth . '-01'))) ?>.</p></div></div>
            <div class="fwp-card__body">
                <dl class="fwp-kv">
                    <dt>PAYE</dt><dd class="fwp-money"><?= $e($money($emp201['totals']['paye'])) ?></dd>
                    <dt>UIF</dt><dd class="fwp-money"><?= $e($money($emp201['totals']['uif'])) ?></dd>
                    <dt>SDL</dt><dd class="fwp-money"><?= $e($money($emp201['totals']['sdl'])) ?></dd>
                    <dt>Pay SARS by</dt><dd><b><?= $e(date('l j F', strtotime($emp201['due']))) ?></b></dd>
                </dl>
            </div>
            <div class="fwp-card__foot"><a class="fwp-mini" href="/payroll/reports.php?month=<?= $e($sarsMonth) ?>"><svg class="i"><use href="#if-report"/></svg>EMP201 details</a></div>
        </section>
    </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/partials/foot.php'; ?>
