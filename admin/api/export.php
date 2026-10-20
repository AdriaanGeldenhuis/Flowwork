<?php
// /admin/api/export.php — company data as CSV files that open in Excel.
//   GET ?type=people | projects | customers | suppliers | audit | summary
//   audit also takes the audit trail's filters: user_id, kind, q, from, to
// Cells that start with = + - @ (or a tab / carriage return) are prefixed with ' so spreadsheets never run
// them. Every export is rate limited and written to the audit trail.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/AdminAudit.php';

$type = (string)($_GET['type'] ?? '');
$types = ['people' => 'People', 'projects' => 'Projects', 'customers' => 'Customers', 'suppliers' => 'Suppliers', 'audit' => 'Audit trail', 'summary' => 'Summary'];
if (!isset($types[$type])) admin_error('Unknown export.');
admin_rate_limit('admin-export', 30, 600);
$cid = $ADMIN_COMPANY_ID;

$rows = [];
$head = [];
switch ($type) {
    case 'people':
        $head = ['First name', 'Surname', 'Email', 'Role', 'Status', 'Paid seat', 'Last sign-in', 'Joined'];
        $st = $DB->prepare("SELECT u.first_name, u.last_name, u.email, uc.role, u.status, u.is_seat, u.last_login_at, u.created_at
                              FROM user_companies uc JOIN users u ON u.id = uc.user_id WHERE uc.company_id = ? ORDER BY u.first_name, u.last_name");
        $st->execute([$cid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [$r['first_name'], $r['last_name'], $r['email'], AdminKit::ROLES[$r['role']][0] ?? $r['role'], ucfirst((string)$r['status']), $r['is_seat'] ? 'Yes' : 'No', $r['last_login_at'], $r['created_at']];
        }
        break;
    case 'projects':
        $head = ['Number', 'Project', 'Status', 'Client', 'Who can see it', 'Start', 'End', 'Budget', 'Created'];
        $st = $DB->prepare("SELECT project_number, name, status, client, visibility, start_date, end_date, budget, created_at FROM projects WHERE company_id = ? ORDER BY created_at");
        $st->execute([$cid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [$r['project_number'], $r['name'], $r['status'], $r['client'], ($r['visibility'] ?? '') === 'company' ? 'Everyone' : 'Members', $r['start_date'], $r['end_date'], $r['budget'], $r['created_at']];
        }
        break;
    case 'customers':
    case 'suppliers':
        $head = ['Name', 'Legal name', 'Registration', 'VAT number', 'Email', 'Phone', 'Website', 'Status', 'Created'];
        $st = $DB->prepare("SELECT name, legal_name, reg_no, vat_no, email, phone, website, status, created_at FROM crm_accounts
                             WHERE company_id = ? AND type = ? AND deleted_at IS NULL ORDER BY name");
        $st->execute([$cid, $type === 'customers' ? 'customer' : 'supplier']);
        foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) $rows[] = $r;
        break;
    case 'audit':
        $f = AdminAudit::filters($_GET);
        [$where, $params] = AdminAudit::where($cid, $f);
        $head = ['When', 'Who', 'Email', 'What', 'Details', 'IP address'];
        $st = $DB->prepare("SELECT COALESCE(al.timestamp, al.created_at) AS at, CONCAT_WS(' ', u.first_name, u.last_name) AS who, u.email, al.action, al.details, al.ip
                              FROM audit_log al LEFT JOIN users u ON u.id = al.user_id WHERE $where ORDER BY al.id DESC LIMIT 20000");
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [$r['at'], trim((string)$r['who']), $r['email'], AdminKit::actionLabel((string)$r['action'])[0], AdminKit::detailText($r['details']), $r['ip']];
        }
        break;
    case 'summary':
        $head = ['What', 'How many'];
        foreach (AdminAudit::counts($DB, $cid) as $label => $n) $rows[] = [$label, $n === null ? '' : $n];
        break;
}

AdminKit::audit($DB, $cid, $ADMIN_USER_ID, 'data_exported', ['export' => $types[$type], 'rows' => count($rows)]);

$safe = function ($v): string {
    $v = (string)($v ?? '');
    if ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false && !is_numeric($v)) $v = "'" . $v;
    return $v;
};
$company = preg_replace('/[^A-Za-z0-9]+/', '-', (string)($DB->query("SELECT name FROM companies WHERE id = " . (int)$cid)->fetchColumn() ?: 'company'));
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . trim($company, '-') . '-' . $type . '-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // so Excel reads the file as UTF-8
fputcsv($out, $head);
foreach ($rows as $r) fputcsv($out, array_map($safe, $r));
fclose($out);
exit;
