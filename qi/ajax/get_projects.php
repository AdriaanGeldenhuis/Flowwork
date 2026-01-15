<?php
// /qi/ajax/get_projects.php
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../db.php';

header('Content-Type: application/json');

$company_id = $_SESSION['company_id'];

try {
    $stmt = $pdo->prepare("
        SELECT project_id, name 
        FROM projects 
        WHERE company_id = ? 
        AND status IN ('active', 'draft')
        AND archived = 0
        ORDER BY name
    ");
    $stmt->execute([$company_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'projects' => $projects]);

} catch (Exception $e) {
    error_log("Get projects error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load projects']);
}