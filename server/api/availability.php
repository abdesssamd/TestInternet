<?php
/**
 * GET /api/availability.php?window=24h|7d|30d[&agent_id=123][&export=csv]
 * Disponibilite (%) Internet/LAN par poste, sur une fenetre glissante.
 * Sans agent_id : disponibilite de tous les postes (pour la page rapport globale).
 * Avec export=csv : telechargement CSV au lieu du JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/Session.php';
require_once __DIR__ . '/../models/NetworkHistory.php';
require_once __DIR__ . '/../models/Agent.php';

Session::start();
Session::requireAuthApi();

$windowMap = ['24h' => 24, '7d' => 24 * 7, '30d' => 24 * 30];
$window = (string) ($_GET['window'] ?? '24h');
$hours = $windowMap[$window] ?? 24;

$agentId = isset($_GET['agent_id']) ? (int) $_GET['agent_id'] : 0;
$export = ($_GET['export'] ?? '') === 'csv';

if ($agentId > 0) {
    $agent = AgentModel::findById($agentId);
    if (!$agent) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Poste introuvable']);
        exit;
    }
    $data = NetworkHistoryModel::availability($agentId, $hours);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'window'   => $window,
        'agent_id' => $agentId,
        'hostname' => $agent['hostname'],
    ] + $data);
    exit;
}

$rows = NetworkHistoryModel::availabilityAllAgents($hours);

if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="disponibilite_' . $window . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Poste', 'Echantillons', 'Disponibilite Internet (%)', 'Disponibilite LAN (%)'], ';');
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['hostname'],
            $row['total_samples'],
            $row['internet_uptime_pct'],
            $row['lan_uptime_pct'],
        ], ';');
    }
    fclose($out);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['window' => $window, 'devices' => $rows]);
