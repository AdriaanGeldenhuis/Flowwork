<?php
// /qi/lib/MilestoneAllocator.php
// Spreads a recorded payment across an invoice's payment schedule (milestones),
// oldest unpaid phase first, then re-derives each phase's status. This keeps the
// schedule's "paid" figures and percentages consistent with the invoice balance
// no matter what amount is paid. It is a no-op for invoices without a schedule.
//
// The caller is responsible for the surrounding transaction and for updating the
// invoice's own balance_due / status.

class MilestoneAllocator {
    /**
     * @param PDO   $DB        Active connection (caller manages the transaction)
     * @param int   $companyId
     * @param int   $invoiceId
     * @param int   $paymentId The payment whose allocation we are recording
     * @param float $amount    Amount being applied to this invoice
     */
    public static function allocate(PDO $DB, int $companyId, int $invoiceId, int $paymentId, float $amount): void {
        $msStmt = $DB->prepare("
            SELECT * FROM payment_milestones
            WHERE entity_type = 'invoice' AND entity_id = ? AND company_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $msStmt->execute([$invoiceId, $companyId]);
        $allMilestones = $msStmt->fetchAll();
        if (!$allMilestones) {
            return; // invoice has no payment schedule
        }

        $linkStmt = $DB->prepare("INSERT INTO milestone_payments (milestone_id, payment_id, amount) VALUES (?, ?, ?)");
        $payStmt  = $DB->prepare("UPDATE payment_milestones SET amount_paid = ?, updated_at = NOW() WHERE id = ?");

        // Waterfall: fill each phase before moving to the next.
        $remaining = round($amount, 2);
        foreach ($allMilestones as $ms) {
            if ($remaining <= 0.005) break;
            $msRemaining = round((float)$ms['amount'] - (float)$ms['amount_paid'], 2);
            if ($msRemaining <= 0.005) continue; // phase already settled
            $alloc = round(min($remaining, $msRemaining), 2);
            if ($alloc <= 0) continue;
            $linkStmt->execute([$ms['id'], $paymentId, $alloc]);
            $payStmt->execute([round((float)$ms['amount_paid'] + $alloc, 2), $ms['id']]);
            $remaining = round($remaining - $alloc, 2);
        }

        // Re-derive each phase's status from how much it has now been paid:
        //   fully paid             -> paid
        //   first still-open phase -> due (or keep overdue if already flagged)
        //   later still-open phases -> pending
        $msStmt->execute([$invoiceId, $companyId]);
        $freshMilestones = $msStmt->fetchAll();
        $statusStmt = $DB->prepare("UPDATE payment_milestones SET status = ? WHERE id = ?");
        $activeAssigned = false;
        foreach ($freshMilestones as $ms) {
            $fullyPaid = ((float)$ms['amount_paid'] >= (float)$ms['amount'] - 0.01);
            if ($fullyPaid) {
                $newStatus = 'paid';
            } elseif ($ms['status'] === 'overdue') {
                $newStatus = 'overdue';
                $activeAssigned = true;
            } elseif (!$activeAssigned) {
                $newStatus = 'due';
                $activeAssigned = true;
            } else {
                $newStatus = 'pending';
            }
            if ($newStatus !== $ms['status']) {
                $statusStmt->execute([$newStatus, $ms['id']]);
            }
        }
    }
}
