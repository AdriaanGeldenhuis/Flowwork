<?php
// Utility functions for evaluating and updating formula columns.  These
// functions are included from API endpoints and board.php to compute
// formula values based on other numeric columns.

/**
 * Evaluate a formula expression for a single item.  Supports bracket
 * references by column ID (e.g. [#12]) or column name (e.g. [Total]).
 * Basic arithmetic operators +, -, *, / and parentheses are
 * respected.  Aggregation functions SUM(), AVG(), MIN(), MAX() are
 * supported with a comma‑separated list of references.  Any
 * undefined reference resolves to 0.  Precision controls the number
 * of decimal places in the returned string.
 *
 * @param string $formula    The formula string from column config.
 * @param array  $context    Map of column_id => numeric value for the current item.
 * @param array  $colNameMap Map of column name => column_id to resolve name references.
 * @param int    $precision  Number of decimal places to round to (default 2).
 * @return string            The calculated value formatted with the given precision.
 */
function _fw_compute_formula(string $formula, array $context, array $colNameMap, int $precision = 2): string
{
    if ($formula === '' || $formula === null) {
        return number_format(0, $precision, '.', '');
    }
    $expr = $formula;
    // Normalize whitespace
    $expr = preg_replace('/\s+/', '', $expr);
    // Evaluate supported functions first.  Recognized functions include SUM, AVG, MIN,
    // MAX, COUNT, ABS, ROUND and SQRT.  Function names are case-insensitive.
    $expr = preg_replace_callback('/\b(SUM|AVG|MIN|MAX|COUNT|ABS|ROUND|SQRT)\(([^\)]*)\)/i', function ($match) use ($context, $colNameMap) {
        $func = strtolower($match[1]);
        $args = $match[2];
        // Split arguments by comma
        $parts = preg_split('/,/', $args);
        $vals = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') continue;
            // If reference is enclosed in brackets, resolve it
            if (preg_match('/^\[(.+?)\]$/', $p, $m)) {
                $ref = $m[1];
                if ($ref !== '') {
                    if ($ref[0] === '#') {
                        $cid = (int)substr($ref, 1);
                        $vals[] = isset($context[$cid]) ? (float)$context[$cid] : 0.0;
                    } else {
                        $name = $ref;
                        if (isset($colNameMap[$name]) && isset($context[$colNameMap[$name]])) {
                            $vals[] = (float)$context[$colNameMap[$name]];
                        } else {
                            $vals[] = 0.0;
                        }
                    }
                }
            } else {
                // Raw numeric literal
                $vals[] = (float)$p;
            }
        }
        // Provide at least a zero value if no arguments were found
        if (empty($vals)) {
            $vals = [0.0];
        }
        switch ($func) {
            case 'sum':
                return array_sum($vals);
            case 'avg':
                return array_sum($vals) / count($vals);
            case 'min':
                return min($vals);
            case 'max':
                return max($vals);
            case 'count':
                return count($vals);
            case 'abs':
                // Use absolute value of the first argument
                return abs($vals[0]);
            case 'round':
                // Round the first argument to zero decimal places
                return round($vals[0]);
            case 'sqrt':
                // Square root of the first argument.  Negative inputs yield 0.
                $v = $vals[0];
                return $v >= 0 ? sqrt($v) : 0.0;
            default:
                return 0.0;
        }
    }, $expr);
    // Replace bracket references outside of aggregations
    $expr = preg_replace_callback('/\[(.*?)\]/', function ($match) use ($context, $colNameMap) {
        $ref = $match[1];
        if ($ref === '') return '0';
        if ($ref[0] === '#') {
            $cid = (int)substr($ref, 1);
            return isset($context[$cid]) ? (float)$context[$cid] : '0';
        } else {
            $name = $ref;
            if (isset($colNameMap[$name]) && isset($context[$colNameMap[$name]])) {
                return (float)$context[$colNameMap[$name]];
            } else {
                return '0';
            }
        }
    }, $expr);
    // Replace exponent operator ^ with PHP ** for correct power handling
    $expr = str_replace('^', '**', $expr);
    // Validate allowed characters: digits, operators, parentheses, decimal points and stars
    if (preg_match('/[^0-9\.\+\-\*\/\(\)\s]/', $expr)) {
        // Contains invalid characters; return zero
        return number_format(0, $precision, '.', '');
    }
    // Evaluate expression safely.  Suppress errors and catch exceptions.
    $result = 0.0;
    try {
        // Use eval in a sandboxed scope.  Casting to float ensures numeric result.
        $tmp = @eval('return ' . $expr . ';');
        if (is_nan($tmp) || is_infinite($tmp)) {
            $result = 0.0;
        } else {
            $result = (float)$tmp;
        }
    } catch (Throwable $e) {
        $result = 0.0;
    }
    return number_format($result, $precision, '.', '');
}

/**
 * Recompute and persist all formula column values for a given item.  This
 * queries the database for formula columns on the board and all
 * existing values for the item, then updates board_item_values with
 * newly calculated results.  Non‑numeric values are treated as 0.
 *
 * @param PDO $DB         Active PDO connection.
 * @param int $boardId    Board ID that the item belongs to.
 * @param int $companyId  Company ID for multi‑tenant isolation.
 * @param int $itemId     The specific item ID to update formulas for.
 */
function _fw_update_formula_columns(PDO $DB, int $boardId, int $companyId, int $itemId): void
{
    // Fetch all formula columns for this board
    $stmt = $DB->prepare("SELECT column_id, name, config FROM board_columns WHERE board_id = ? AND company_id = ? AND type = 'formula'");
    $stmt->execute([$boardId, $companyId]);
    $formulaCols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$formulaCols) {
        return;
    }
    // Load all current values for the item across columns
    $stmt = $DB->prepare(
        "SELECT v.column_id, v.value, c.name, c.type, c.config
         FROM board_item_values v
         JOIN board_columns c ON v.column_id = c.column_id
         WHERE v.item_id = ? AND c.board_id = ? AND c.company_id = ?"
    );
    $stmt->execute([$itemId, $boardId, $companyId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $context = [];
    $nameMap = [];
    foreach ($rows as $row) {
        $cid = (int)$row['column_id'];
        $nameMap[$row['name']] = $cid;
        // Determine numeric value; numbers and previously computed formulas are stored as strings
        $val = $row['value'];
        if ($val === null || $val === '') {
            $num = 0.0;
        } elseif (is_numeric($val)) {
            $num = (float)$val;
        } else {
            // Attempt to cast to float
            $num = (float)$val;
        }
        $context[$cid] = $num;
    }
    // Evaluate each formula and persist
    $upsert = $DB->prepare(
        "INSERT INTO board_item_values (item_id, column_id, value)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    );
    foreach ($formulaCols as $fcol) {
        $cfg = [];
        if (!empty($fcol['config'])) {
            $tmp = json_decode($fcol['config'], true);
            if (is_array($tmp)) $cfg = $tmp;
        }
        $formulaStr = $cfg['formula'] ?? '';
        $precision  = isset($cfg['precision']) ? (int)$cfg['precision'] : 2;
        $res = _fw_compute_formula($formulaStr, $context, $nameMap, $precision);
        $upsert->execute([$itemId, (int)$fcol['column_id'], $res]);
        // Update context for subsequent formulas referencing this formula column
        $context[(int)$fcol['column_id']] = (float)$res;
    }
}