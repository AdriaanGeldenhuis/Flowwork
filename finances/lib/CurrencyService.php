<?php
// finances/lib/CurrencyService.php
//
// Multi-currency helper. Provides exchange rate lookup and conversion
// for foreign currency transactions. Uses the gl_exchange_rates table.
// SARS requires translation at the rate on the date of the transaction
// (Section 25D, Income Tax Act).

class CurrencyService
{
    private $db;
    private $companyId;
    private $baseCurrency = 'ZAR';

    public function __construct(PDO $db, int $companyId)
    {
        $this->db = $db;
        $this->companyId = $companyId;
    }

    /**
     * Get the exchange rate for a currency on a specific date.
     * Falls back to the most recent rate before that date if exact date not found.
     *
     * @param string $currencyCode  ISO 4217 code (e.g. 'USD')
     * @param string $date          YYYY-MM-DD
     * @return float|null  Rate to ZAR (1 unit = X ZAR), or null if not found
     */
    public function getRate(string $currencyCode, string $date): ?float
    {
        $currencyCode = strtoupper(trim($currencyCode));
        if ($currencyCode === $this->baseCurrency) {
            return 1.0;
        }

        // Try exact date first
        $stmt = $this->db->prepare(
            "SELECT rate_to_zar FROM gl_exchange_rates
             WHERE company_id = ? AND currency_code = ? AND rate_date = ?
             LIMIT 1"
        );
        $stmt->execute([$this->companyId, $currencyCode, $date]);
        $rate = $stmt->fetchColumn();

        if ($rate !== false) {
            return (float)$rate;
        }

        // Fallback: most recent rate before the given date
        $stmt = $this->db->prepare(
            "SELECT rate_to_zar FROM gl_exchange_rates
             WHERE company_id = ? AND currency_code = ? AND rate_date <= ?
             ORDER BY rate_date DESC LIMIT 1"
        );
        $stmt->execute([$this->companyId, $currencyCode, $date]);
        $rate = $stmt->fetchColumn();

        return ($rate !== false) ? (float)$rate : null;
    }

    /**
     * Convert an amount from a foreign currency to ZAR.
     *
     * @param float  $amount        Amount in foreign currency
     * @param string $currencyCode  ISO 4217 code
     * @param string $date          Transaction date
     * @return float|null  Amount in ZAR, or null if no rate available
     */
    public function toZAR(float $amount, string $currencyCode, string $date): ?float
    {
        $rate = $this->getRate($currencyCode, $date);
        if ($rate === null) return null;
        return round($amount * $rate, 2);
    }

    /**
     * Convert an amount from ZAR to a foreign currency.
     *
     * @param float  $amountZAR     Amount in ZAR
     * @param string $currencyCode  ISO 4217 code
     * @param string $date          Transaction date
     * @return float|null  Amount in foreign currency, or null if no rate available
     */
    public function fromZAR(float $amountZAR, string $currencyCode, string $date): ?float
    {
        $rate = $this->getRate($currencyCode, $date);
        if ($rate === null || $rate == 0) return null;
        return round($amountZAR / $rate, 2);
    }

    /**
     * Save or update an exchange rate.
     *
     * @param string $currencyCode
     * @param string $date
     * @param float  $rateToZAR
     * @param string $source  e.g. 'manual', 'sarb', 'api'
     */
    public function saveRate(string $currencyCode, string $date, float $rateToZAR, string $source = 'manual'): void
    {
        $currencyCode = strtoupper(trim($currencyCode));
        $stmt = $this->db->prepare(
            "INSERT INTO gl_exchange_rates (company_id, currency_code, rate_date, rate_to_zar, source)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rate_to_zar = VALUES(rate_to_zar), source = VALUES(source)"
        );
        $stmt->execute([$this->companyId, $currencyCode, $date, $rateToZAR, $source]);
    }

    /**
     * Get all rates for a currency within a date range.
     *
     * @param string $currencyCode
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    public function getRates(string $currencyCode, string $startDate, string $endDate): array
    {
        $stmt = $this->db->prepare(
            "SELECT rate_date, rate_to_zar, source FROM gl_exchange_rates
             WHERE company_id = ? AND currency_code = ?
               AND rate_date BETWEEN ? AND ?
             ORDER BY rate_date DESC"
        );
        $stmt->execute([$this->companyId, strtoupper(trim($currencyCode)), $startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * List all currencies that have rates configured.
     *
     * @return array  Array of currency codes
     */
    public function getConfiguredCurrencies(): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT currency_code FROM gl_exchange_rates WHERE company_id = ? ORDER BY currency_code"
        );
        $stmt->execute([$this->companyId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
