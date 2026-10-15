<?php
// /mail/cron/auto_link_crm.php — Automatically link emails to CRM accounts
//
// Scans mail that has no CRM link yet and links it to the CRM customer /
// supplier whose contact (crm_contacts.email) or account e-mail matches the
// sender or a recipient. Links are written to email_links with linked_type =
// crm_accounts.type ('customer' | 'supplier'), so they show on the CRM
// account timeline. Runs without a session.
//
// Cron (hourly): 7 * * * * php /path/to/mail/cron/auto_link_crm.php >/dev/null 2>&1
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../lib/autoload.php';

try {
    MailSchema::ensure($DB);

    $companies = $DB->query("SELECT DISTINCT company_id FROM emails")->fetchAll(PDO::FETCH_COLUMN);
    if (!$companies) {
        echo "No companies with emails found.\n";
        return;
    }

    // Recent mail (last 90 days) without any CRM link.
    $stmtUnlinked = $DB->prepare(
        "SELECT e.email_id, e.sender, e.to_recipients, e.cc_recipients
           FROM emails e
          WHERE e.company_id = ? AND e.folder NOT IN ('Drafts','Outbox','Spam','Trash')
            AND COALESCE(e.sent_at, e.created_at) >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            AND NOT EXISTS (SELECT 1 FROM email_links l WHERE l.email_id = e.email_id AND l.linked_type IN ('customer','supplier'))
          LIMIT 2000"
    );
    $stmtContact = $DB->prepare(
        "SELECT DISTINCT a.id, a.type
           FROM crm_contacts c
           JOIN crm_accounts a ON a.id = c.account_id AND a.company_id = c.company_id
          WHERE c.company_id = ? AND LOWER(c.email) = ? AND a.deleted_at IS NULL
         UNION
         SELECT a.id, a.type FROM crm_accounts a
          WHERE a.company_id = ? AND LOWER(a.email) = ? AND a.deleted_at IS NULL"
    );
    $stmtInsertLink = $DB->prepare(
        "INSERT INTO email_links (company_id, email_id, linked_type, linked_id, created_by, created_at)
         VALUES (?, ?, ?, ?, 0, NOW())"
    );

    $linked = 0;
    foreach ($companies as $companyId) {
        $companyId = (int)$companyId;
        $stmtUnlinked->execute([$companyId]);
        $cache = [];
        foreach ($stmtUnlinked->fetchAll(PDO::FETCH_ASSOC) as $email) {
            $addresses = [];
            foreach (['sender', 'to_recipients', 'cc_recipients'] as $field) {
                foreach (MimeParser::parseAddressList((string)$email[$field]) as $a) $addresses[$a['email']] = true;
            }
            $done = [];
            foreach (array_keys($addresses) as $addr) {
                if (!isset($cache[$addr])) {
                    try {
                        $stmtContact->execute([$companyId, $addr, $companyId, $addr]);
                        $cache[$addr] = $stmtContact->fetchAll(PDO::FETCH_ASSOC);
                    } catch (PDOException $e) {
                        // crm_accounts.deleted_at missing on older schemas
                        $cache[$addr] = [];
                    }
                }
                foreach ($cache[$addr] as $m) {
                    $sig = $m['type'] . ':' . $m['id'];
                    if (isset($done[$sig]) || !in_array($m['type'], ['customer', 'supplier'], true)) continue;
                    $done[$sig] = true;
                    $stmtInsertLink->execute([$companyId, (int)$email['email_id'], $m['type'], (int)$m['id']]);
                    $linked++;
                }
            }
        }
    }

    echo "Email CRM auto-linking completed: $linked link(s) added.\n";
} catch (Throwable $e) {
    error_log('Auto-link CRM error: ' . $e->getMessage());
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}
