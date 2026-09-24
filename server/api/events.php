<?php
/**
 * GET /api/events.php?type=&limit=
 * Historique global des evenements (toutes machines).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/Session.php';
require_once __DIR__ . '/../models/Event.php';

Session::start();
Session::requireAuthApi();

header('Content-Type: application/json; charset=utf-8');

$type = $_GET['type'] ?? null;
$validTypes = [
    'AGENT_ONLINE', 'AGENT_OFFLINE', 'WIFI_CONNECTED', 'WIFI_DISCONNECTED',
    'INTERNET_ON', 'INTERNET_OFF', 'INTERFACE_ADDED', 'INTERFACE_REMOVED',
    'IP_CHANGED', 'GATEWAY_CHANGED',
];
if ($type !== null && !in_array($type, $validTypes, true)) {
    $type = null;
}

$limit = min(500, max(1, (int) ($_GET['limit'] ?? 100)));

$events = EventModel::recent($limit, $type);

echo json_encode(['events' => $events]);
