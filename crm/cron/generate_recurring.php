<?php
// /qi/cron/generate_recurring.php
// Run daily: 0 2 * * * /usr/bin/php /path/to/qi/cron/generate_recurring.php

require_once __DIR__ . '/../../init.php';

try {
    // Find all active recurring invoices due today or earlier
    $stmt = $DB->prepare("
        SELECT * FROM recurring_invoices 
        WHERE active = 1 
        AND next_run_date <= CURDATE()
        AND (end_date IS NULL OR end_date >= CURDATE())
    ");
    $stmt->execute();
    $recurring = $stmt->fetchAll();

    foreach ($recurring as $rec) {
        try {
            $DB->beginTransaction();

            // Fetch lines
            $stmt = $DB->prepare("SELECT * FROM recurring_invoice_lines WHERE recurring_invoice_id = ? ORDER BY sort_order");
            $stmt->execute([$rec['id']]);
            $lines = $stmt->fetchAll();

            // Generate invoice number using race-proof sequence allocation (see Section 1)
            $year = date('Y');
            $seqStmt = $DB->prepare("SELECT next_number FROM qi_sequences WHERE company_id = ? AND type = 'invoice' AND year = ? FOR UPDATE");
            $seqStmt->execute([$rec['company_id'], $year]);
            $seqRow = $seqStmt->fetch(PDO::FETCH_ASSOC);
            if (!$seqRow) {
                // Insert new sequence row starting at 0
                $insertSeq = $DB->prepare("INSERT INTO qi_sequences (company_id, type, year, next_number) VALUES (?, 'invoice', ?, 0)");
                $insertSeq->execute([$rec['company_id'], $year]);
                $nextNum = 1;
            } else {
                $nextNum = intval($seqRow['next_number']) + 1;
            }
            $updateSeq = $DB->prepare("UPDATE qi_sequences SET next_number = ? WHERE company_id = ? AND type = 'invoice' AND year = ?");
            $updateSeq->execute([$nextNum, $rec['company_id'], $year]);
            $invoiceNumber = sprintf('INV%d-%04d', $year, $nextNum);

            // Calculate totals
            $subtotal = 0;
            $discount = 0;
            $tax = 0;

            foreach ($lines as $line) {
                $qty = floatval($line['quantity']);
                $price = floatval($line['unit_price']);
                $disc = floatval($line['discount']);
                $taxRate = floatval($line['tax_rate']);

                $lineSubtotal = $qty * $price;
                $lineNet = $lineSubtotal - $disc;
                $lineTax = $lineNet * ($taxRate / 100);

                $subtotal += $lineSubtotal;
                $discount += $disc;
                $tax += $lineTax;
            }

            $total = $subtotal - $discount + $tax;

            // Create invoice
            $issueDate = date('Y-m-d');
            $dueDate = date('Y-m-d', strtotime('+30 days'));

            $stmt = $DB->prepare("
                INSERT INTO invoices (
                    company_id, invoice_number, customer_id,
                    issue_date, due_date, status,
                    subtotal, discount, tax, total, balance_due, currency,
                    terms, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, 'ZAR', ?, ?, ?)
            ");
            $stmt->execute([
                $rec['company_id'], $invoiceNumber, $rec['customer_id'],
                $issueDate, $dueDate,
                $subtotal, $discount, $tax, $total, $total,
                $rec['terms'], 'Auto-generated from recurring: ' . $rec['template_name'], $rec['created_by']
            ]);

            $invoiceId = $DB->lastInsertId();

            // Copy lines
            $sortOrder = 0;
            foreach ($lines as $line) {
                $qty = floatval($line['quantity']);
                $price = floatval($line['unit_price']);
                $disc = floatval($line['discount']);
                $taxRate = floatval($line['tax_rate']);

                $lineSubtotal = $qty * $price;
                $lineNet = $lineSubtotal - $disc;
                $lineTax = $lineNet * ($taxRate / 100);
                $lineTotal = $lineNet + $lineTax;

                $stmt = $DB->prepare("
                    INSERT INTO invoice_lines (
                        invoice_id, item_description, quantity, unit, unit_price, discount, tax_rate, line_total, sort_order
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $invoiceId, $line['item_description'], $qty, $line['unit'],
                    $price, $disc, $taxRate, $lineTotal, $sortOrder++
                ]);
            }

            // Calculate next run date
            $nextDate = date('Y-m-d', strtotime($rec['next_run_date']));
            
            switch ($rec['frequency']) {
                case 'weekly':
                    $nextDate = date('Y-m-d', strtotime($nextDate . ' +' . $rec['interval_count'] . ' weeks'));
                    break;
                case 'monthly':
                    $nextDate = date('Y-m-d', strtotime($nextDate . ' +' . $rec['interval_count'] . ' months'));
                    break;
                case 'quarterly':
                    $nextDate = date('Y-m-d', strtotime($nextDate . ' +' . ($rec['interval_count'] * 3) . ' months'));
                    break;
                case 'yearly':
                    $nextDate = date('Y-m-d', strtotime($nextDate . ' +' . $rec['interval_count'] . ' years'));
                    break;
            }

            $stmt = $DB->prepare("
                UPDATE recurring_invoices 
                SET next_run_date = ?, last_generated_date = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$nextDate, $rec['id']]);

            // TODO: Auto-send email to customer

            $DB->commit();

            echo "Generated invoice {$invoiceNumber} from recurring {$rec['id']}\n";

        } catch (Exception $e) {
            $DB->rollBack();
            error_log("Recurring generation failed for ID {$rec['id']}: " . $e->getMessage());
        }
    }

    echo "Cron completed. Processed " . count($recurring) . " recurring invoices.\n";

} catch (Exception $e) {
    error_log("Recurring cron error: " . $e->getMessage());
}