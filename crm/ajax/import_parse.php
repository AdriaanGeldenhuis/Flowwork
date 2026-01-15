<?php
// /crm/ajax/import_parse.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

try {
    if (!isset($_FILES['file'])) {
        throw new Exception('No file uploaded');
    }

    $file = $_FILES['file'];
    $type = $_POST['type'] ?? 'accounts';

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Upload error: ' . $file['error']);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['csv', 'xlsx'];

    if (!in_array($ext, $allowedExts)) {
        throw new Exception('Invalid file type. Only CSV and XLSX are supported.');
    }

    $headers = [];
    $sampleData = [];
    $totalRows = 0;

    if ($ext === 'csv') {
        // Parse CSV
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            throw new Exception('Could not open file');
        }

        // Read headers
        $headers = fgetcsv($handle);
        if (!$headers) {
            throw new Exception('File is empty or invalid');
        }

        // Read first 10 rows for sample
        while (($row = fgetcsv($handle)) && count($sampleData) < 10) {
            $sampleData[] = $row;
            $totalRows++;
        }

        // Count remaining rows
        while (fgetcsv($handle)) {
            $totalRows++;
        }

        fclose($handle);

    } elseif ($ext === 'xlsx') {
        // Parse XLSX using SimpleXLSX (you'll need to include this library)
        // For now, we'll return an error suggesting CSV
        throw new Exception('XLSX support requires additional library. Please use CSV format or contact support.');
    }

    // Store parsed data in session for later use
    $_SESSION['import_temp'] = [
        'file_name' => $file['name'],
        'file_path' => $file['tmp_name'],
        'type' => $type,
        'headers' => $headers,
        'total_rows' => $totalRows,
        'uploaded_at' => time()
    ];

    echo json_encode([
        'ok' => true,
        'headers' => $headers,
        'sample_data' => $sampleData,
        'total_rows' => $totalRows
    ]);

} catch (Exception $e) {
    error_log("Import parse error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}