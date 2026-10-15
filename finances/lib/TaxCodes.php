<?php
// finances/lib/TaxCodes.php
//
// Single source of truth for VAT rates and tax codes. The standard rate
// comes from the company's gl_tax_codes (code STD) — never from a hardcoded
// 15 sprinkled through save paths. Rates live per company so a statutory
// rate change is one data update, not a code hunt.

require_once __DIR__ . '/FinanceBootstrap.php';

class TaxCodes
{
    private PDO $db;
    private int $companyId;
    /** @var array<int, array>|null cache: tax_code_id => row */
    private ?array $cache = null;

    public function __construct(PDO $db, int $companyId)
    {
        $this->db = $db;
        $this->companyId = $companyId;
    }

    /**
     * All active tax codes for the company, keyed by tax_code_id. The core
     * codes (STD/ZERO/EXEMPT/INPUT) the document forms send are provisioned
     * first when any is missing — otherwise saving a line fails with
     * "Unknown tax code 'ZERO'".
     */
    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = $this->load();
            $present = [];
            foreach ($this->cache as $row) {
                $present[strtoupper(trim((string)$row['code']))] = true;
            }
            if (array_diff_key(FinanceBootstrap::CORE_TAX_CODES, $present)
                && FinanceBootstrap::ensureTaxCodes($this->db, $this->companyId)) {
                $this->cache = $this->load();
            }
        }
        return $this->cache;
    }

    private function load(): array
    {
        $stmt = $this->db->prepare(
            "SELECT tax_code_id, code, description, rate_percent, type
               FROM gl_tax_codes WHERE company_id = ? AND is_active = 1"
        );
        $stmt->execute([$this->companyId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(int)$row['tax_code_id']] = $row;
        }
        return $rows;
    }

    /** The company's standard VAT rate percent (STD code), e.g. 15.0. */
    public function standardRatePercent(): float
    {
        $row = $this->byCode('STD') ?? $this->byCode('STANDARD');
        return $row ? (float)$row['rate_percent'] : 15.0;
    }

    public function byCode(string $code): ?array
    {
        foreach ($this->all() as $row) {
            if (strcasecmp($row['code'], $code) === 0) {
                return $row;
            }
        }
        return null;
    }

    public function byId(?int $taxCodeId): ?array
    {
        if (!$taxCodeId) {
            return null;
        }
        return $this->all()[$taxCodeId] ?? null;
    }

    /**
     * Resolve an OUTPUT-side tax code id for a document line. Accepts either
     * an explicit tax code (id or code string) or falls back on the rate:
     * rate > 0 -> STD, rate == 0 -> ZERO. Throws when an explicit code is
     * unknown or its rate disagrees with the line's rate.
     */
    public function resolveOutputForLine(?int $taxCodeId, ?string $code, float $rate): ?int
    {
        if ($taxCodeId) {
            $row = $this->byId($taxCodeId);
            if (!$row) {
                throw new Exception("Unknown tax code id $taxCodeId");
            }
            if (abs((float)$row['rate_percent'] - $rate) > 0.005) {
                throw new Exception(sprintf(
                    'Line VAT rate %.2f%% does not match tax code %s (%.2f%%)',
                    $rate, $row['code'], (float)$row['rate_percent']
                ));
            }
            return (int)$row['tax_code_id'];
        }
        if ($code !== null && $code !== '') {
            $row = $this->byCode($code);
            if (!$row) {
                throw new Exception("Unknown tax code '$code'");
            }
            if (abs((float)$row['rate_percent'] - $rate) > 0.005) {
                throw new Exception(sprintf(
                    "Line VAT rate %.2f%% does not match tax code %s (%.2f%%)",
                    $rate, $row['code'], (float)$row['rate_percent']
                ));
            }
            return (int)$row['tax_code_id'];
        }
        // Rate-only fallback
        if ($rate > 0.005) {
            $std = $this->byCode('STD') ?? $this->byCode('STANDARD');
            if ($std && abs((float)$std['rate_percent'] - $rate) > 0.005) {
                throw new Exception(sprintf(
                    'VAT rate %.2f%% matches no active tax code (standard is %.2f%%)',
                    $rate, (float)$std['rate_percent']
                ));
            }
            return $std ? (int)$std['tax_code_id'] : null;
        }
        $zero = $this->byCode('ZERO');
        return $zero ? (int)$zero['tax_code_id'] : null;
    }
}
