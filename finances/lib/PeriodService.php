<?php
// finances/lib/PeriodService.php
//
// Utility class for working with financial period locks. Many posting
// operations must respect locked periods so that no new transactions can
// be added to prior periods once the books are closed. This class
// encapsulates the logic for checking whether a given date is in a locked
// period for the current company.

class PeriodService
{
    private $db;
    private $companyId;

    public function __construct(PDO $db, int $companyId)
    {
        $this->db = $db;
        $this->companyId = $companyId;
    }

    /**
     * Determine if the provided date falls into a locked accounting period.
     * Returns true if the period is locked (i.e. posting is not allowed),
     * otherwise false. If no locks exist the method returns false.
     *
     * @param string $date A date in YYYY-MM-DD format
     * @return bool
     */
    public function isLocked(string $date): bool
    {
        // Fetch the latest lock date for the company
        $stmt = $this->db->prepare(
            "SELECT MAX(lock_date) AS latest_lock FROM gl_period_locks WHERE company_id = ?"
        );
        $stmt->execute([$this->companyId]);
        $latestLock = $stmt->fetchColumn();
        if (!$latestLock) {
            return false;
        }
        // Compare dates lexicographically (YYYY-MM-DD)
        return $date <= $latestLock;
    }
}
