<?php
// Project Profit & Loss figures, shared by export/csv/project_pl.php and
// export/pdf/project_pl.php. All amounts are ZAR, excluding VAT.
//
// Income   = issued invoices of the project (not draft/cancelled/deleted),
//            per line: a line on one of the project's boards counts for that
//            board's project, any other line for the invoice's project;
//            less approved/applied credit notes against the project's invoices.
// Expenses = posted journal lines on expense accounts tagged with the project
//            or one of its boards: AP bills and receipts once posted (their
//            lines carry the board, and the project), manual journals, and the
//            cost of sales of the project's invoices.
// Every query is scoped to the company.

/**
 * @return array<int, array{project_id:int, name:string, income:float, expenses:float, net:float}>
 *         Active projects (not archived/cancelled) by name, or only $projectId when given.
 */
function project_pl_rows(PDO $db, int $companyId, int $projectId = 0): array
{
    $sql = "SELECT project_id, project_number, name FROM projects WHERE company_id = ?";
    $params = [$companyId];
    if ($projectId > 0) {
        $sql .= " AND project_id = ?";
        $params[] = $projectId;
    } else {
        $sql .= " AND status NOT IN ('archived','cancelled') AND (archived = 0 OR archived IS NULL)";
    }
    $stmt = $db->prepare($sql . " ORDER BY name, project_id");
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $number = trim((string)($p['project_number'] ?? ''));
        $rows[(int)$p['project_id']] = [
            'project_id' => (int)$p['project_id'],
            'name'       => ($number !== '' ? $number . ' ' : '') . $p['name'],
            'income'     => 0.0,
            'expenses'   => 0.0,
            'net'        => 0.0,
        ];
    }
    if (!$rows) {
        return [];
    }

    // Adds "project id => amount" pairs into one column of $rows.
    $add = function (PDOStatement $stmt, string $col, float $sign = 1.0) use (&$rows): void {
        foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $pid => $amount) {
            if (isset($rows[(int)$pid])) {
                $rows[(int)$pid][$col] += $sign * (float)$amount;
            }
        }
    };
    // Document currency → ZAR, as PostingService::resolveFx does.
    $fx = fn(string $t) => "(CASE WHEN UPPER(COALESCE($t.currency, 'ZAR')) IN ('', 'ZAR') OR COALESCE($t.exchange_rate, 0) <= 0 THEN 1 ELSE $t.exchange_rate END)";

    // Income: invoice lines net of line discount, excl. VAT (line_total includes VAT).
    $stmt = $db->prepare(
        "SELECT COALESCE(pb.project_id, p.project_id) AS pid,
                SUM(ROUND(il.quantity * il.unit_price - COALESCE(il.discount, 0), 2) * " . $fx('i') . ") AS amount
           FROM invoice_lines il
           JOIN invoices i ON i.id = il.invoice_id
      LEFT JOIN project_boards pb ON pb.board_id = il.project_board_id AND pb.company_id = i.company_id
      LEFT JOIN projects p ON p.project_id = i.project_id AND p.company_id = i.company_id
          WHERE i.company_id = ? AND i.deleted_at IS NULL AND i.status NOT IN ('draft','cancelled')
            AND (pb.project_id IS NOT NULL OR p.project_id IS NOT NULL)
       GROUP BY pid"
    );
    $stmt->execute([$companyId]);
    $add($stmt, 'income');

    // Credit notes (posted once approved) reduce the income of the invoice's project.
    $stmt = $db->prepare(
        "SELECT p.project_id AS pid,
                SUM(ROUND(cl.quantity * cl.unit_price - COALESCE(cl.discount, 0), 2) * " . $fx('cn') . ") AS amount
           FROM credit_note_lines cl
           JOIN credit_notes cn ON cn.id = cl.credit_note_id
           JOIN invoices i ON i.id = cn.invoice_id AND i.company_id = cn.company_id
           JOIN projects p ON p.project_id = i.project_id AND p.company_id = i.company_id
          WHERE cn.company_id = ? AND cn.status IN ('approved','applied')
       GROUP BY p.project_id"
    );
    $stmt->execute([$companyId]);
    $add($stmt, 'income', -1.0);

    // Expenses: posted GL expense lines tagged with the project, or with one
    // of its boards when the line has no (valid) project tag.
    $stmt = $db->prepare(
        "SELECT COALESCE(p.project_id, pb.project_id) AS pid, SUM(jl.debit - jl.credit) AS amount
           FROM journal_lines jl
           JOIN journal_entries je ON je.id = jl.journal_id AND je.company_id = ? AND je.status = 'posted'
           JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = je.company_id AND ga.account_type = 'expense'
      LEFT JOIN projects p ON p.project_id = jl.project_id AND p.company_id = je.company_id
      LEFT JOIN project_boards pb ON pb.board_id = jl.board_id AND pb.company_id = je.company_id
          WHERE jl.project_id IS NOT NULL OR jl.board_id IS NOT NULL
       GROUP BY pid"
    );
    $stmt->execute([$companyId]);
    $add($stmt, 'expenses');

    foreach ($rows as &$r) {
        $r['income']   = round($r['income'], 2);
        $r['expenses'] = round($r['expenses'], 2);
        $r['net']      = round($r['income'] - $r['expenses'], 2);
    }
    unset($r);
    return array_values($rows);
}
