<?php
// /crm/ajax/export.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

$companyId = $_SESSION['company_id'];
$type = $_GET['type'] ?? 'accounts';
$format = $_GET['format'] ?? 'csv';
$includeInactive = ($_GET['include_inactive'] ?? '0') === '1';

try {
    $data = [];
    $filename = '';

    if ($type === 'accounts' || $type === 'suppliers' || $type === 'customers') {
        $sql = "
            SELECT 
                name, legal_name, reg_no, vat_no, email, phone, website, 
                status, type, notes, created_at
            FROM crm_accounts
            WHERE company_id = ?
        ";
        
        if ($type === 'suppliers') {
            $sql .= " AND type = 'supplier'";
        } elseif ($type === 'customers') {
            $sql .= " AND type = 'customer'";
        }

        if (!$includeInactive) {
            $sql .= " AND status = 'active'";
        }

        $sql .= " ORDER BY name ASC";

        $stmt = $DB->prepare($sql);
        $stmt->execute([$companyId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $filename = $type . '_export_' . date('Y-m-d');

    } elseif ($type === 'contacts') {
        $sql = "
            SELECT 
                a.name as account_name,
                c.first_name, c.last_name, c.role_title,
                c.email, c.phone, c.is_primary, c.created_at
            FROM crm_contacts c
            JOIN crm_accounts a ON a.id = c.account_id
            WHERE c.company_id = ?
            ORDER BY a.name, c.first_name
        ";

        $stmt = $DB->prepare($sql);
        $stmt->execute([$companyId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $filename = 'contacts_export_' . date('Y-m-d');

    } elseif ($type === 'addresses') {
        $sql = "
            SELECT 
                a.name as account_name,
                ad.type, ad.line1, ad.line2, ad.city, ad.region,
                ad.postal_code, ad.country, ad.created_at
            FROM crm_addresses ad
            JOIN crm_accounts a ON a.id = ad.account_id
            WHERE ad.company_id = ?
            ORDER BY a.name, ad.type
        ";

        $stmt = $DB->prepare($sql);
        $stmt->execute([$companyId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $filename = 'addresses_export_' . date('Y-m-d');
    }

    if (empty($data)) {
        throw new Exception('No data to export');
    }

    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

        $output = fopen('php://output', 'w');
        
        // Headers
        fputcsv($output, array_keys($data[0]));
        
        // Data
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        
        fclose($output);

    } elseif ($format === 'xlsx') {
        // For XLSX, you'd need a library like PHPSpreadsheet
        // For now, fall back to CSV
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, array_keys($data[0]));
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
    }

} catch (Exception $e) {
    error_log("Export error: " . $e->getMessage());
    die('Export failed: ' . $e->getMessage());
}