<?php
// /mail/cron/auto_link_crm.php — Automatically link emails to CRM accounts
//
// This script scans all unlinked email messages and links them to
// CRM customer/supplier accounts based on matching email addresses
// found in the sender or recipient fields.  It should be executed
// periodically via a cron job and does not require an authenticated
// user session.  The script iterates over all companies and
// attempts to find matching CRM contacts for each message.  When a
// match is found, a row is inserted into the email_links table with
// linked_type set to 'customer' or 'supplier' according to the
// associated crm_accounts.type.  Duplicate links are ignored.

require_once __DIR__ . '/../init.php';

// no auth gate needed; this runs without a session

try {
    // Fetch all companies that have email messages
    $stmtCompanies = $DB->prepare("SELECT DISTINCT company_id FROM emails");
    $stmtCompanies->execute();
    $companies = $stmtCompanies->fetchAll(PDO::FETCH_COLUMN);

    if (!$companies) {
        echo "No companies with emails found.\n";
        return;
    }

    // Prepare statements reused in loops
    // Get email IDs that do not have any CRM links yet for a company
    $stmtUnlinked = $DB->prepare(
        "SELECT e.email_id, e.sender, e.to_recipients, e.cc_recipients, e.bcc_recipients " .
        "FROM emails e " .
        "LEFT JOIN email_links l ON l.email_id = e.email_id AND l.company_id = e.company_id " .
        "WHERE e.company_id = ? AND l.email_id IS NULL"
    );

    // Query for matching contact account and type for a given address
    $stmtContact = $DB->prepare(
        "SELECT c.account_id, a.type " .
        "FROM crm_contacts c " .
        "JOIN crm_accounts a ON a.id = c.account_id AND a.company_id = c.company_id " .
        "WHERE c.email = ? AND c.company_id = ?"
    );

    // Insert link
    $stmtInsertLink = $DB->prepare(
        "INSERT INTO email_links " .
        "(company_id, email_id, linked_type, linked_id, created_by, created_at) " .
        "VALUES (?, ?, ?, ?, ?, NOW())"
    );

    foreach ($companies as $companyId) {
        $companyId = (int)$companyId;
        // Fetch unlinked messages for this company
        $stmtUnlinked->execute([$companyId]);
        $emails = $stmtUnlinked->fetchAll(PDO::FETCH_ASSOC);
        if (!$emails) continue;

        foreach ($emails as $email) {
            $emailId = (int)$email['email_id'];
            // Build a set of addresses to check: sender, to, cc, bcc
            $addresses = [];
            $fields = ['sender','to_recipients','cc_recipients','bcc_recipients'];
            foreach ($fields as $field) {
                if (!empty($email[$field])) {
                    $parts = explode(',', $email[$field]);
                    foreach ($parts as $addr) {
                        $addr = trim($addr);
                        if ($addr !== '') $addresses[$addr] = true;
                    }
                }
            }

            // For each distinct address attempt to find a contact
            foreach (array_keys($addresses) as $addr) {
                // Normalize address to lower-case
                $normalized = strtolower($addr);
                // Query contact
                $stmtContact->execute([$normalized, $companyId]);
                $matches = $stmtContact->fetchAll(PDO::FETCH_ASSOC);
                if (!$matches) continue;
                foreach ($matches as $match) {
                    $accountId = (int)$match['account_id'];
                    $linkedType = $match['type']; // expected 'customer' or 'supplier'
                    if (!$accountId || !$linkedType) continue;
                    try {
                        // created_by=0 indicates system
                        $stmtInsertLink->execute([$companyId, $emailId, $linkedType, $accountId, 0]);
                    } catch (Exception $ex) {
                        // ignore duplicate link errors
                    }
                }
            }
        }
    }

    echo "Email CRM auto-linking completed successfully.\n";
} catch (Exception $e) {
    // Log errors for cron monitoring
    error_log('Auto-link CRM error: ' . $e->getMessage());
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}