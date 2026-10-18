<?php
// projects/lib/Formula.php
//
// Formula columns. A small expression language evaluated by a parser — never
// eval(). Compatible with the formulas already stored on boards:
//   {Qty} * {Rate}        [Qty]*[Rate]        [#12] / 2
// Column names match exactly first, then ignoring case and repeated spaces
// (stored formulas such as "{TotAL DAYS} * {Rate}" used to resolve to 0).
//
// Operators: + - * / % ^, comparisons = == != <> < <= > >=, AND OR NOT (also && || !)
// Functions: SUM AVG MIN MAX COUNT ABS ROUND(x[,n]) ROUNDUP ROUNDDOWN SQRT POWER MOD
//            IF(cond, a[, b]) AND OR NOT TODAY() DAYS(a, b) WORKDAYS(start, end) CONCAT(…)
// Dates (date and timeline columns) count as day numbers, so {Due} - TODAY() is
// "days left". Text compares case-insensitively: IF({Status} = "Done", 1, 0).

final class Formula
{
    private array $tok = [];
    private int $pos = 0;
    /** @var callable(string): (float|string|null) */
    private $resolve;

    private function __construct(callable $resolve)
    {
        $this->resolve = $resolve;
    }

    /**
     * Evaluate a formula.
     * @param callable(string $ref): (float|string|null) $resolve  '#12' or a column name → value
     * @return float|string|null  null when the formula cannot be evaluated (shown as empty)
     */
    public static function evaluate(string $formula, callable $resolve)
    {
        $formula = trim($formula);
        if ($formula === '') return null;
        $f = new self($resolve);
        try {
            $f->tok = self::tokenize($formula);
            if (!$f->tok) return null;
            $v = $f->parseOr();
            if ($f->pos < count($f->tok)) return null;
            if (is_float($v) && (is_nan($v) || is_infinite($v))) return null;
            return $v;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Format a result the way it is stored in board_item_values. */
    public static function format($value, int $precision): string
    {
        if ($value === null) return '';
        if (is_string($value)) return mb_substr($value, 0, 1000);
        return number_format((float)$value, max(0, min(8, $precision)), '.', '');
    }

    /** Column names a formula refers to (for dependency ordering and the editor). @return string[] */
    public static function references(string $formula): array
    {
        preg_match_all('/\{([^{}]+)\}|\[([^\[\]]+)\]/', $formula, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $x) $out[] = trim($x[1] !== '' ? $x[1] : ($x[2] ?? ''));
        return array_values(array_unique(array_filter($out, 'strlen')));
    }

    /** Lower-cased name with single spaces — the loose match key for column names. */
    public static function nameKey(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)));
    }

    /** Day number (days since 1970-01-01, UTC) of a 'Y-m-d' date, or null. */
    public static function dayNumber(?string $date): ?float
    {
        if (!$date || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
        return (float)intdiv(gmmktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]), 86400);
    }

    // ------------------------------------------------------------------ tokens

    private static function tokenize(string $s): array
    {
        $out = [];
        $n = strlen($s);
        $i = 0;
        while ($i < $n) {
            $c = $s[$i];
            if (ctype_space($c)) { $i++; continue; }
            if ($c === '{' || $c === '[') {
                $close = $c === '{' ? '}' : ']';
                $j = strpos($s, $close, $i + 1);
                if ($j === false) throw new RuntimeException('unclosed reference');
                $out[] = ['ref', trim(substr($s, $i + 1, $j - $i - 1))];
                $i = $j + 1;
                continue;
            }
            if ($c === '"' || $c === "'") {
                $j = $i + 1;
                $buf = '';
                while ($j < $n && $s[$j] !== $c) { $buf .= $s[$j]; $j++; }
                if ($j >= $n) throw new RuntimeException('unclosed text');
                $out[] = ['str', $buf];
                $i = $j + 1;
                continue;
            }
            if (ctype_digit($c) || ($c === '.' && $i + 1 < $n && ctype_digit($s[$i + 1]))) {
                $j = $i;
                while ($j < $n && (ctype_digit($s[$j]) || $s[$j] === '.')) $j++;
                if ($j < $n && ($s[$j] === 'e' || $s[$j] === 'E') && $j + 1 < $n && (ctype_digit($s[$j + 1]) || (($s[$j + 1] === '-' || $s[$j + 1] === '+') && $j + 2 < $n && ctype_digit($s[$j + 2])))) {
                    $j += 2;
                    while ($j < $n && ctype_digit($s[$j])) $j++;
                }
                $num = substr($s, $i, $j - $i);
                if (!is_numeric($num)) throw new RuntimeException('bad number');
                $out[] = ['num', (float)$num];
                $i = $j;
                continue;
            }
            if (ctype_alpha($c) || $c === '_') {
                $j = $i;
                while ($j < $n && (ctype_alnum($s[$j]) || $s[$j] === '_')) $j++;
                $word = strtoupper(substr($s, $i, $j - $i));
                $out[] = in_array($word, ['AND', 'OR', 'NOT'], true) ? ['op', $word] : ['id', $word];
                $i = $j;
                continue;
            }
            $two = substr($s, $i, 2);
            if (in_array($two, ['<=', '>=', '!=', '<>', '==', '&&', '||', '**'], true)) {
                $map = ['&&' => 'AND', '||' => 'OR', '**' => '^', '==' => '=', '<>' => '!='];
                $out[] = ['op', $map[$two] ?? $two];
                $i += 2;
                continue;
            }
            if (strpos('+-*/%^()<>=,!', $c) !== false) {
                $out[] = ['op', $c === '!' ? 'NOT' : $c];
                $i++;
                continue;
            }
            throw new RuntimeException('unexpected character');
        }
        return $out;
    }

    private function peek(): ?array { return $this->tok[$this->pos] ?? null; }
    private function isOp(string $op): bool { $t = $this->peek(); return $t !== null && $t[0] === 'op' && $t[1] === $op; }
    private function expect(string $op): void
    {
        if (!$this->isOp($op)) throw new RuntimeException('expected ' . $op);
        $this->pos++;
    }

    // ------------------------------------------------------------------ grammar

    private function parseOr()
    {
        $v = $this->parseAnd();
        while ($this->isOp('OR')) { $this->pos++; $r = $this->parseAnd(); $v = (self::truthy($v) || self::truthy($r)) ? 1.0 : 0.0; }
        return $v;
    }

    private function parseAnd()
    {
        $v = $this->parseCmp();
        while ($this->isOp('AND')) { $this->pos++; $r = $this->parseCmp(); $v = (self::truthy($v) && self::truthy($r)) ? 1.0 : 0.0; }
        return $v;
    }

    private function parseCmp()
    {
        $v = $this->parseAdd();
        foreach (['=', '!=', '<=', '>=', '<', '>'] as $op) {
            if ($this->isOp($op)) {
                $this->pos++;
                $r = $this->parseAdd();
                return self::compare($v, $r, $op) ? 1.0 : 0.0;
            }
        }
        return $v;
    }

    private function parseAdd()
    {
        $v = $this->parseMul();
        while ($this->isOp('+') || $this->isOp('-')) {
            $op = $this->tok[$this->pos++][1];
            $r = $this->parseMul();
            $v = $op === '+' ? self::num($v) + self::num($r) : self::num($v) - self::num($r);
        }
        return $v;
    }

    private function parseMul()
    {
        $v = $this->parsePow();
        while ($this->isOp('*') || $this->isOp('/') || $this->isOp('%')) {
            $op = $this->tok[$this->pos++][1];
            $r = self::num($this->parsePow());
            if ($op === '*') $v = self::num($v) * $r;
            else {
                if ($r == 0.0) throw new RuntimeException('division by zero');
                $v = $op === '/' ? self::num($v) / $r : fmod(self::num($v), $r);
            }
        }
        return $v;
    }

    private function parsePow()
    {
        $v = $this->parseUnary();
        if ($this->isOp('^')) {
            $this->pos++;
            $r = $this->parsePow();
            $v = pow(self::num($v), self::num($r));
        }
        return $v;
    }

    private function parseUnary()
    {
        if ($this->isOp('-')) { $this->pos++; return -self::num($this->parseUnary()); }
        if ($this->isOp('+')) { $this->pos++; return self::num($this->parseUnary()); }
        if ($this->isOp('NOT')) { $this->pos++; return self::truthy($this->parseUnary()) ? 0.0 : 1.0; }
        return $this->parsePrimary();
    }

    private function parsePrimary()
    {
        $t = $this->peek();
        if ($t === null) throw new RuntimeException('unexpected end');
        $this->pos++;
        switch ($t[0]) {
            case 'num': return $t[1];
            case 'str': return $t[1];
            case 'ref':
                $v = ($this->resolve)($t[1]);
                return $v === null ? 0.0 : $v;
            case 'op':
                if ($t[1] === '(') { $v = $this->parseOr(); $this->expect(')'); return $v; }
                // AND(a, b) / OR(a, b) written as functions
                if (($t[1] === 'AND' || $t[1] === 'OR') && $this->isOp('(')) return self::call($t[1], $this->parseArgs());
                throw new RuntimeException('unexpected operator');
            case 'id':
                if ($t[1] === 'TRUE') return 1.0;
                if ($t[1] === 'FALSE') return 0.0;
                if ($t[1] === 'PI' && !$this->isOp('(')) return M_PI;
                return self::call($t[1], $this->parseArgs());
        }
        throw new RuntimeException('unexpected token');
    }

    private function parseArgs(): array
    {
        $this->expect('(');
        $args = [];
        if (!$this->isOp(')')) {
            $args[] = $this->parseOr();
            while ($this->isOp(',')) { $this->pos++; $args[] = $this->parseOr(); }
        }
        $this->expect(')');
        return $args;
    }

    // ------------------------------------------------------------------ functions

    private static function call(string $fn, array $a)
    {
        $nums = fn() => array_map([self::class, 'num'], $a);
        $arg = fn(int $i) => self::num($a[$i] ?? 0.0);
        switch ($fn) {
            case 'SUM': return array_sum($nums());
            case 'AVG': case 'AVERAGE': return $a ? array_sum($nums()) / count($a) : 0.0;
            case 'MIN': return $a ? min($nums()) : 0.0;
            case 'MAX': return $a ? max($nums()) : 0.0;
            case 'COUNT': return (float)count(array_filter($a, fn($v) => $v !== '' && $v !== null && !(is_float($v) && $v == 0.0)));
            case 'ABS': return abs($arg(0));
            case 'SQRT': return $arg(0) >= 0 ? sqrt($arg(0)) : 0.0;
            case 'POWER': return pow($arg(0), $arg(1));
            case 'MOD': if ($arg(1) == 0.0) throw new RuntimeException('division by zero'); return fmod($arg(0), $arg(1));
            case 'ROUND': return round($arg(0), (int)$arg(1));
            case 'ROUNDUP': $p = pow(10, (int)$arg(1)); return ceil($arg(0) * $p) / $p;
            case 'ROUNDDOWN': $p = pow(10, (int)$arg(1)); return floor($arg(0) * $p) / $p;
            case 'IF': return self::truthy($a[0] ?? 0.0) ? ($a[1] ?? 1.0) : ($a[2] ?? 0.0);
            case 'AND': foreach ($a as $v) if (!self::truthy($v)) return 0.0; return 1.0;
            case 'OR': foreach ($a as $v) if (self::truthy($v)) return 1.0; return 0.0;
            case 'NOT': return self::truthy($a[0] ?? 0.0) ? 0.0 : 1.0;
            case 'TODAY': return (float)self::dayNumber(date('Y-m-d'));
            case 'DAYS': return $arg(0) - $arg(1);
            case 'WORKDAYS': case 'NETWORKDAYS':
                return (float)self::workdays((int)$arg(0), (int)$arg(1));
            case 'CONCAT': return implode('', array_map(fn($v) => is_float($v) ? rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') : (string)$v, $a));
        }
        throw new RuntimeException('unknown function ' . $fn);
    }

    /** Weekdays (Mon–Fri) from start to end inclusive; negative when end is before start. */
    private static function workdays(int $start, int $end): int
    {
        if ($start === 0 || $end === 0) return 0;
        $sign = 1;
        if ($end < $start) { [$start, $end] = [$end, $start]; $sign = -1; }
        $days = $end - $start + 1;
        $weeks = intdiv($days, 7);
        $count = $weeks * 5;
        $dow = ($start + 3) % 7; // 1970-01-01 was a Thursday; 0 = Monday
        for ($i = 0; $i < $days % 7; $i++) if ((($dow + $i) % 7) < 5) $count++;
        return $sign * $count;
    }

    private static function num($v): float
    {
        if (is_float($v) || is_int($v)) return (float)$v;
        $v = trim((string)$v);
        if ($v === '') return 0.0;
        $clean = str_replace([',', ' ', 'R'], '', $v);
        return is_numeric($clean) ? (float)$clean : 0.0;
    }

    private static function truthy($v): bool
    {
        if (is_string($v)) return trim($v) !== '' && trim($v) !== '0';
        return (float)$v != 0.0;
    }

    private static function compare($a, $b, string $op): bool
    {
        if (is_string($a) || is_string($b)) {
            $sa = mb_strtolower(trim(is_float($a) ? self::numText($a) : (string)$a));
            $sb = mb_strtolower(trim(is_float($b) ? self::numText($b) : (string)$b));
            if (is_numeric($sa) && is_numeric($sb)) { $a = (float)$sa; $b = (float)$sb; }
            else {
                $c = strcmp($sa, $sb);
                return ['=' => $c === 0, '!=' => $c !== 0, '<' => $c < 0, '<=' => $c <= 0, '>' => $c > 0, '>=' => $c >= 0][$op];
            }
        }
        $a = (float)$a; $b = (float)$b;
        $eq = abs($a - $b) < 1e-9;
        return ['=' => $eq, '!=' => !$eq, '<' => $a < $b && !$eq, '<=' => $a < $b || $eq, '>' => $a > $b && !$eq, '>=' => $a > $b || $eq][$op];
    }

    private static function numText(float $v): string
    {
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
    }
}
