<?php
// /payroll/lib/PayPayslips.php — payslips: drawing, storing and emailing.
//
//   pdf()          one payslip as PDF bytes; runs that are not finalised get
//                  a DRAFT band and are never stored
//   generateAll()  writes every payslip of a finalised run to
//                  storage/payroll/{company}/run_{run}/payslip_{employee}.pdf
//                  (storage/ is closed to the web; files are served through
//                  /payroll/api/payslip.php after the role check)
//   emailAll()     emails each person their payslip from the company's first
//                  mailbox that can send (the same rule Admin → Mail shows),
//                  else the server's own mail; the result is kept per person

require_once __DIR__ . '/PayTax.php';
require_once __DIR__ . '/PayKit.php';
require_once __DIR__ . '/PayslipPdf.php';

final class PayPayslips
{
    /** Everything one payslip needs. */
    private static function data(PDO $db, int $companyId, array $run, int $employeeId): array
    {
        $st = $db->prepare("SELECT x.*, e.first_name, e.last_name, e.employee_no, e.job_title, e.id_number, e.tax_number, e.hire_date, e.pay_frequency,
                                   e.bank_name, e.bank_account_no, e.branch_code, e.email
                              FROM pay_run_employees x JOIN employees e ON e.id = x.employee_id AND e.company_id = x.company_id
                             WHERE x.run_id = ? AND x.company_id = ? AND x.employee_id = ?");
        $st->execute([$run['id'], $companyId, $employeeId]);
        $emp = $st->fetch(PDO::FETCH_ASSOC);
        if (!$emp) throw new PayProblem('They are not in this pay run.');
        $st = $db->prepare("SELECT l.qty, l.rate_cents, l.amount_cents, l.description, pi.name AS item_name, pi.type
                              FROM pay_run_lines l LEFT JOIN payitems pi ON pi.id = l.payitem_id
                             WHERE l.run_id = ? AND l.company_id = ? AND l.employee_id = ? ORDER BY l.is_adhoc, l.id");
        $st->execute([$run['id'], $companyId, $employeeId]);
        $lines = $st->fetchAll(PDO::FETCH_ASSOC);
        $start = PayTax::taxYearStart($run['pay_date']);
        $st = $db->prepare("SELECT COALESCE(SUM(x.gross_cents + x.reimbursements_cents),0) gross, COALESCE(SUM(x.taxable_income_cents),0) taxable, COALESCE(SUM(x.paye_cents),0) paye,
                                   COALESCE(SUM(x.uif_employee_cents),0) uif_emp, COALESCE(SUM(x.net_cents),0) net
                              FROM pay_run_employees x JOIN pay_runs r ON r.id = x.run_id
                             WHERE x.company_id = ? AND x.employee_id = ? AND r.pay_date BETWEEN ? AND ?
                               AND (r.status IN ('locked','posted') OR r.id = ?)");
        $st->execute([$companyId, $employeeId, $start, $run['pay_date'], in_array($run['status'], ['locked', 'posted'], true) ? $run['id'] : 0]);
        $ytd = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $st = $db->prepare("SELECT * FROM companies WHERE id = ?");
        $st->execute([$companyId]);
        $company = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $st = $db->prepare("SELECT paye_reference FROM payroll_settings WHERE company_id = ?");
        try {
            $st->execute([$companyId]);
            $company['paye_reference'] = (string)$st->fetchColumn();
        } catch (PDOException $e) {
            $company['paye_reference'] = '';
        }
        return [$company, $emp, $lines, $ytd, $start];
    }

    public static function pdf(PDO $db, int $companyId, int $runId, int $employeeId): array
    {
        $run = self::run($db, $companyId, $runId);
        $final = in_array($run['status'], ['locked', 'posted'], true);
        [$company, $emp, $lines, $ytd, $start] = self::data($db, $companyId, $run, $employeeId);
        $path = self::path($companyId, $runId, $employeeId);
        if ($final && is_file($path) && !empty($emp['payslip_path'])) $bytes = (string)file_get_contents($path);
        else $bytes = renderPayslipPdf($company, $run, $emp, $lines, $ytd, $start, !$final);
        return [$bytes, self::filename($emp, $run)];
    }

    public static function generateAll(PDO $db, int $companyId, int $runId): int
    {
        $run = self::run($db, $companyId, $runId);
        if (!in_array($run['status'], ['locked', 'posted'], true)) throw new PayProblem('Payslips are created when the run is finalised.');
        $dir = dirname(self::path($companyId, $runId, 0));
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('Cannot create the payslip folder ' . $dir);
        $st = $db->prepare("SELECT employee_id FROM pay_run_employees WHERE run_id = ? AND company_id = ?");
        $st->execute([$runId, $companyId]);
        $upd = $db->prepare("UPDATE pay_run_employees SET payslip_path = ?, payslip_generated_at = NOW() WHERE run_id = ? AND company_id = ? AND employee_id = ?");
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $eid) {
            [$company, $emp, $lines, $ytd, $start] = self::data($db, $companyId, $run, (int)$eid);
            $file = self::path($companyId, $runId, (int)$eid);
            if (file_put_contents($file, renderPayslipPdf($company, $run, $emp, $lines, $ytd, $start, false)) === false) continue;
            @chmod($file, 0640);
            $upd->execute(['storage/payroll/' . $companyId . '/run_' . $runId . '/' . basename($file), $runId, $companyId, (int)$eid]);
            $n++;
        }
        return $n;
    }

    /**
     * Email payslips. $onlyMissing skips people already emailed successfully,
     * so pressing the button twice never sends anyone a second copy.
     * @return array{sent:int, skipped:int, failed:int, no_email:array, errors:array, via:string}
     */
    public static function emailAll(PDO $db, int $companyId, int $userId, int $runId, bool $onlyMissing = true, ?int $employeeId = null): array
    {
        $run = self::run($db, $companyId, $runId);
        if (!in_array($run['status'], ['locked', 'posted'], true)) throw new PayProblem('Finalise the run before emailing payslips.');
        $box = self::mailbox($db, $companyId);
        $out = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'no_email' => [], 'errors' => [], 'via' => $box ? (string)$box['email_address'] : 'server'];
        $sql = "SELECT x.id, x.employee_id, x.payslip_emailed_at, e.first_name, e.last_name, e.email FROM pay_run_employees x JOIN employees e ON e.id = x.employee_id
                 WHERE x.run_id = ? AND x.company_id = ?";
        $args = [$runId, $companyId];
        if ($employeeId) { $sql .= " AND x.employee_id = ?"; $args[] = $employeeId; }
        $st = $db->prepare($sql);
        $st->execute($args);
        $upd = $db->prepare("UPDATE pay_run_employees SET payslip_emailed_at = ?, payslip_email_error = ? WHERE id = ?");
        $company = $db->query("SELECT name FROM companies WHERE id = " . (int)$companyId)->fetchColumn() ?: 'Payroll';
        $pass = $box ? SecureVault::decrypt($box['password_encrypted'] ?? '') : '';
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $name = trim($p['first_name'] . ' ' . $p['last_name']);
            $to = trim((string)$p['email']);
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { $out['no_email'][] = $name; $out['skipped']++; continue; }
            if ($onlyMissing && !empty($p['payslip_emailed_at'])) { $out['skipped']++; continue; }
            try {
                [$bytes, $filename] = self::pdf($db, $companyId, $runId, (int)$p['employee_id']);
            } catch (Throwable $e) {
                $out['failed']++;
                $out['errors'][] = $name . ': the payslip could not be created';
                $upd->execute([null, 'The payslip could not be created', $p['id']]);
                error_log('[payroll] payslip pdf: ' . $e->getMessage());
                continue;
            }
            [$subject, $html] = self::message($run, (string)$p['first_name'], (string)$company);
            $att = [['filename' => $filename, 'mime' => 'application/pdf', 'content' => $bytes]];
            if ($box) {
                $res = SmtpSender::send([
                    'host' => $box['smtp_server'], 'port' => (int)$box['smtp_port'], 'encryption' => $box['smtp_encryption'],
                    'tls_verify' => (int)($box['tls_verify'] ?? 1) === 1, 'public_only' => true,
                    'username' => $box['username'] ?: $box['email_address'], 'password' => $pass,
                    'from' => $box['email_address'], 'from_name' => (string)$company,
                    'to' => [$to], 'subject' => $subject, 'html' => $html, 'text' => strip_tags(str_replace(['</p>', '<br>'], "\n", $html)), 'attachments' => $att,
                ]);
            } else {
                $res = self::phpMail('payroll@' . self::host(), (string)$company, $to, $subject, $html, $att);
            }
            if (!empty($res['ok'])) {
                $out['sent']++;
                $upd->execute([date('Y-m-d H:i:s'), null, $p['id']]);
            } else {
                $out['failed']++;
                $err = mb_substr((string)($res['error'] ?? 'not sent'), 0, 250);
                $out['errors'][] = $name . ': ' . $err;
                $upd->execute([null, $err, $p['id']]);
            }
        }
        PayKit::audit($db, $companyId, $userId, 'payslips_emailed', ['run' => $run['run_number'] . ' · ' . $run['name'], 'sent' => $out['sent'], 'failed' => $out['failed']], 'pay_run', $runId);
        return $out;
    }

    /** The company's first mailbox that can send (lowest id, active, with SMTP). */
    public static function mailbox(PDO $db, int $companyId): ?array
    {
        require_once dirname(__DIR__, 2) . '/mail/lib/SecureVault.php';
        require_once dirname(__DIR__, 2) . '/mail/lib/SmtpSender.php';
        try {
            $st = $db->prepare("SELECT account_id, email_address, smtp_server, smtp_port, smtp_encryption, username, password_encrypted, tls_verify
                                  FROM email_accounts WHERE company_id = ? AND is_active = 1 AND smtp_server IS NOT NULL AND smtp_server <> '' ORDER BY account_id LIMIT 1");
            $st->execute([$companyId]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function message(array $run, string $first, string $company): array
    {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $subject = 'Your payslip — ' . $run['name'];
        $html = '<p>Hi ' . $e($first) . ',</p><p>Your payslip for <strong>' . $e($run['name']) . '</strong> (' . $e(PayKit::period($run['period_start'], $run['period_end']))
              . ') is attached. Pay day: <strong>' . $e(PayKit::date($run['pay_date'])) . '</strong>.</p>'
              . '<p>The PDF holds personal information — please keep it safe.</p><p>Regards,<br>' . $e($company) . '</p>';
        return [$subject, $html];
    }

    private static function filename(array $emp, array $run): string
    {
        $name = preg_replace('/[^A-Za-z0-9]+/', '-', trim($emp['first_name'] . ' ' . $emp['last_name']));
        return 'Payslip-' . trim($name, '-') . '-' . date('Y-m-d', strtotime($run['pay_date'])) . '.pdf';
    }

    public static function path(int $companyId, int $runId, int $employeeId): string
    {
        return dirname(__DIR__, 2) . '/storage/payroll/' . $companyId . '/run_' . $runId . '/payslip_' . $employeeId . '.pdf';
    }

    private static function run(PDO $db, int $companyId, int $runId): array
    {
        $st = $db->prepare("SELECT * FROM pay_runs WHERE id = ? AND company_id = ?");
        $st->execute([$runId, $companyId]);
        $run = $st->fetch(PDO::FETCH_ASSOC);
        if (!$run) throw new PayProblem('That pay run no longer exists.');
        return $run;
    }

    private static function host(): string
    {
        $base = defined('APP_BASE_URL') && APP_BASE_URL ? (string)parse_url(APP_BASE_URL, PHP_URL_HOST) : '';
        return preg_replace('/^www\./', '', $base ?: 'flowwork.app');
    }

    /** No mailbox: the server's own mail() with the PDF attached (it may land in spam). */
    private static function phpMail(string $from, string $fromName, string $to, string $subject, string $html, array $attachments): array
    {
        if (!function_exists('mail')) return ['ok' => false, 'error' => 'Connect a mailbox in Mail — this server cannot send mail by itself.'];
        $mixed = 'mx' . bin2hex(random_bytes(6));
        $alt = 'al' . bin2hex(random_bytes(6));
        $body = "--$mixed\r\nContent-Type: multipart/alternative; boundary=\"$alt\"\r\n\r\n"
              . "--$alt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode(strip_tags($html))) . "\r\n"
              . "--$alt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n--$alt--\r\n";
        foreach ($attachments as $a) {
            $fn = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$a['filename']);
            $body .= "--$mixed\r\nContent-Type: {$a['mime']}; name=\"$fn\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$fn\"\r\n\r\n"
                   . chunk_split(base64_encode($a['content'])) . "\r\n";
        }
        $body .= "--$mixed--\r\n";
        $name = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $headers = "From: $name <$from>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$mixed\"\r\n";
        $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        return @mail($to, $subj, $body, $headers) ? ['ok' => true] : ['ok' => false, 'error' => 'The server could not send it — connect a mailbox in Mail.'];
    }
}
