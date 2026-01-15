<?php
// /crm/ajax/import_template.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

$type = $_GET['type'] ?? 'accounts';

$templates = [
    'accounts' => [
        'filename' => 'accounts_template.csv',
        'headers' => ['name', 'legal_name', 'reg_no', 'vat_no', 'email', 'phone', 'website', 'status', 'industry', 'region', 'notes'],
        'sample' => ['ABC Suppliers', 'ABC Suppliers Pty Ltd', '2021/123456/07', '4123456789', 'info@abc.co.za', '+27123456789', 'https://abc.co.za', 'active', 'Construction', 'Gauteng', 'Preferred supplier']
    ],
    'contacts' => [
        'filename' => 'contacts_template.csv',
        'headers' => ['account_name', 'first_name', 'last_name', 'role_title', 'email', 'phone', 'is_primary'],
        'sample' => ['ABC Suppliers', 'John', 'Doe', 'Sales Manager', 'john@abc.co.za', '+27123456789', '1']
    ],
    'addresses' => [
        'filename' => 'addresses_template.csv',
        'headers' => ['account_name', 'type', 'line1', 'line2', 'city', 'region', 'postal_code', 'country'],
        'sample' => ['ABC Suppliers', 'head_office', '123 Main Road', 'Building A', 'Johannesburg', 'Gauteng', '2001', 'ZA']
    ]
];

$template = $templates[$type] ?? $templates['accounts'];

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $template['filename'] . '"');

$output = fopen('php://output', 'w');
fputcsv($output, $template['headers']);
fputcsv($output, $template['sample']);
fclose($output);