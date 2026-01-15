<?php
// finances/lib/InventoryService.php
//
// The InventoryService provides helper methods for managing stock movements
// and cost calculations. It supports receiving items into inventory,
// issuing items from inventory using a weighted average cost method, and
// computing on-hand quantities and average costs. It also enforces
// negative stock protection based on a company setting (inventory_allow_negative).

class InventoryService
{
    private $db;
    private $companyId;
    private $allowNegative;

    /**
     * Constructor
     *
     * @param PDO $db
     * @param int $companyId
     */
    public function __construct(PDO $db, int $companyId)
    {
        $this->db = $db;
        $this->companyId = $companyId;
        // Determine whether negative inventory is allowed from company settings
        $stmt = $db->prepare(
            "SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = 'inventory_allow_negative' LIMIT 1"
        );
        $stmt->execute([$companyId]);
        $value = $stmt->fetchColumn();
        $this->allowNegative = ($value && strval($value) !== '0');
    }

    /**
     * Get the current on-hand quantity for an item.
     *
     * @param int $itemId
     * @return float
     */
    public function getOnHand(int $itemId): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(qty), 0) AS on_hand
             FROM inventory_movements
             WHERE company_id = ? AND item_id = ?"
        );
        $stmt->execute([$this->companyId, $itemId]);
        $onHand = $stmt->fetchColumn();
        return $onHand !== null ? floatval($onHand) : 0.0;
    }

    /**
     * Compute the weighted average cost per unit for an item based on all
     * inventory movements. If there are no movements or the on-hand
     * quantity is zero, returns zero.
     *
     * @param int $itemId
     * @return float
     */
    public function getAverageCost(int $itemId): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(qty * unit_cost), 0) AS total_cost,
                    COALESCE(SUM(qty), 0) AS total_qty
             FROM inventory_movements
             WHERE company_id = ? AND item_id = ?"
        );
        $stmt->execute([$this->companyId, $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalQty = floatval($row['total_qty']);
        if (abs($totalQty) < 0.0001) {
            return 0.0;
        }
        $totalCost = floatval($row['total_cost']);
        return $totalCost / $totalQty;
    }

    /**
     * Record a receipt of inventory. This increases on-hand quantity and
     * stores the unit cost. Returns the total cost of the movement.
     *
     * @param int $itemId The inventory item ID
     * @param float $qty The quantity received (must be positive)
     * @param float $unitCost The unit cost of this receipt
     * @param string $date The movement date (YYYY-MM-DD)
     * @param string $refType A reference type (e.g. 'ap_bill')
     * @param mixed $refId A reference id (can be null)
     * @return float The total cost (qty * unitCost)
     */
    public function receive(int $itemId, float $qty, float $unitCost, string $date, string $refType = '', $refId = null): float
    {
        if ($qty <= 0) {
            throw new Exception('Receive quantity must be positive');
        }
        // Insert movement record
        $stmt = $this->db->prepare(
            "INSERT INTO inventory_movements (company_id, item_id, movement_date, qty, unit_cost, ref_type, ref_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $this->companyId,
            $itemId,
            $date,
            $qty,
            $unitCost,
            $refType ?: null,
            $refId !== '' ? $refId : null
        ]);
        return $qty * $unitCost;
    }

    /**
     * Issue inventory for a given quantity. Computes the weighted average cost
     * and inserts a negative movement. Returns the total cost of the issued
     * quantity. If negative stock is not allowed and insufficient on-hand,
     * throws an exception.
     *
     * @param int $itemId The inventory item ID
     * @param float $qty The quantity to issue (must be positive)
     * @param string $date The movement date (YYYY-MM-DD)
     * @param string $refType Reference type (e.g. 'invoice')
     * @param mixed $refId Reference id
     * @return float The total cost of items issued
     * @throws Exception if not enough stock and negative not allowed
     */
    public function issue(int $itemId, float $qty, string $date, string $refType = '', $refId = null): float
    {
        if ($qty <= 0) {
            throw new Exception('Issue quantity must be positive');
        }
        $onHand = $this->getOnHand($itemId);
        if (!$this->allowNegative && ($onHand - $qty) < -0.0001) {
            throw new Exception('Insufficient inventory for item ' . $itemId);
        }
        $avgCost = $this->getAverageCost($itemId);
        // Record negative movement
        $stmt = $this->db->prepare(
            "INSERT INTO inventory_movements (company_id, item_id, movement_date, qty, unit_cost, ref_type, ref_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $this->companyId,
            $itemId,
            $date,
            -$qty,
            $avgCost,
            $refType ?: null,
            $refId !== '' ? $refId : null
        ]);
        return $qty * $avgCost;
    }
}