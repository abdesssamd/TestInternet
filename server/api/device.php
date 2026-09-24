<?php
/**
 * GET /api/device.php?id=123
 * Detail complet d'un poste : infos, interfaces, historique recent, evenements.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/Session.php';
require_once __DIR__ . '/../models/Agent.php';
require_once __DIR__ . '/../models/NetworkAdapter.php';
require_once __DIR__ . '/../models/Event.php';
require_once __DIR__ . '/../config/database.php';

Session::start();
Session::requireAuthApi();

header('Content-Type: application/json; charset=utf-8');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'id invalide']);
    exit;
}

$agent = AgentModel::findById($id);
if (!$agent) {
    http_response_code(404);
    echo json_encode(['error' => 'Poste introuvable']);
    exit;
}

$now = time();
$lastSeen = $agent['last_seen'] ? strtotime($agent['last_seen']) : null;
$online = $lastSeen !== null && ($now - $lastSeen) <= OFFLINE_THRESHOLD_SECONDS;

$adapters = NetworkAdapterModel::forAgent($id);
$events = EventModel::forAgent($id, 50);

$pdo = Database::getConnection();
$histStmt = $pdo->prepare(
    'SELECT internet_status, internet_latency, recorded_at FROM network_history
     WHERE agent_id = :id ORDER BY recorded_at DESC LIMIT 100'
);
$histStmt->execute(['id' => $id]);
$history = array_reverse($histStmt->fetchAll());

echo json_encode([
    'device' => [
        'id'                  => (int) $agent['id'],
        'hostname'            => $agent['hostname'],
        'os_name'             => $agent['os_name'],
        'os_version'          => $agent['os_version'],
        'current_user'        => $agent['current_user'],
        'domain'              => $agent['domain'],
        'primary_ip'          => $agent['primary_ip'],
        'primary_mac'         => $agent['primary_mac'],
        'gateway'             => $agent['gateway'],
        'ethernet_active'     => (bool) $agent['ethernet_active'],
        'wifi_active'         => (bool) $agent['wifi_active'],
        'wifi_ssid'           => $agent['wifi_ssid'],
        'internet_status'     => (bool) $agent['internet_status'],
        'internet_test'       => $agent['internet_test'],
        'internet_latency'    => $agent['internet_latency'],
        'internet_checked_at' => $agent['internet_checked_at'],
        'agent_version'       => $agent['agent_version'],
        'last_seen'           => $agent['last_seen'],
        'online'              => $online,
        'is_active'           => (bool) $agent['is_active'],
        'token_hint'          => $agent['token_hint'],
        // Coupure Internet : consigne serveur + etat confirme par l'agent.
        'internet_blocked'        => (int) $agent['internet_blocked'],
        'internet_block_applied'  => (int) $agent['internet_block_applied'],
        'internet_block_by'       => $agent['internet_block_by'],
        'internet_block_at'       => $agent['internet_block_at'],
        'internet_block_reason'   => $agent['internet_block_reason'] ?? null,
        'is_monitored'            => (int) ($agent['is_monitored'] ?? 1),
    ],
    'adapters' => $adapters,
    'events'   => $events,
    'history'  => $history,
]);
