<?php
/**
 * POST /api/neighbors.php
 * Recoit la liste des voisins ARP vus localement par un agent (scan LAN periodique,
 * cadence independante du rapport principal). Sert a detecter les appareils
 * presents sur le reseau mais non geres par un agent ("appareils fantomes").
 * Auth: header X-Agent-Token
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/TokenAuth.php';
require_once __DIR__ . '/../middleware/RateLimiter.php';
require_once __DIR__ . '/../models/Setting.php';
require_once __DIR__ . '/../models/LanNeighbor.php';
require_once __DIR__ . '/../models/Event.php';
require_once __DIR__ . '/../services/AlertEngine.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Methode non autorisee']);
    exit;
}

$agent = TokenAuth::requireValid();
$agentId = (int) $agent['id'];
$hostname = $agent['hostname'];

$minInterval = SettingModel::getInt('ghost_scan_min_interval_seconds', 900);
if (RateLimiter::tooManyRequests($agentId, 'neighbors', $minInterval)) {
    RateLimiter::log($agentId, 'neighbors', 429);
    http_response_code(429);
    echo json_encode(['error' => 'Trop de requetes, reessayez plus tard']);
    exit;
}

$raw = file_get_contents('php://input', false, null, 0, API_MAX_PAYLOAD_BYTES + 1);
if ($raw === false || strlen($raw) > API_MAX_PAYLOAD_BYTES) {
    RateLimiter::log($agentId, 'neighbors', 413);
    http_response_code(413);
    echo json_encode(['error' => 'Payload trop volumineux']);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    RateLimiter::log($agentId, 'neighbors', 400);
    http_response_code(400);
    echo json_encode(['error' => 'JSON invalide']);
    exit;
}

$neighbors = $payload['neighbors'] ?? [];
if (!is_array($neighbors)) {
    $neighbors = [];
}
$neighbors = array_slice($neighbors, 0, 500);

$macPattern = '/^[0-9A-Fa-f]{2}([:-][0-9A-Fa-f]{2}){5}$/';
$cleanNeighbors = [];
foreach ($neighbors as $n) {
    if (!is_array($n) || empty($n['mac'])) {
        continue;
    }
    $mac = strtoupper(str_replace('-', ':', substr((string) $n['mac'], 0, 17)));
    if (!preg_match($macPattern, str_replace('-', ':', $mac))) {
        continue;
    }
    $cleanNeighbors[] = [
        'mac' => $mac,
        'ip'  => isset($n['ip']) ? substr((string) $n['ip'], 0, 45) : null,
    ];
}

$pdo = Database::getConnection();

try {
    $pdo->beginTransaction();

    $newUnmanaged = 0;
    foreach ($cleanNeighbors as $n) {
        $result = LanNeighborModel::upsert($n['mac'], $n['ip'], $agentId);
        if ($result['is_new'] && !$result['is_known']) {
            EventModel::log($agentId, 'GHOST_DEVICE_DETECTED', "$hostname a detecte un appareil non gere : {$n['mac']}", $n);
            AlertEngine::ghostDevice($agentId, $hostname, $n['mac'], $n['ip']);
            $newUnmanaged++;
        }
    }

    $pdo->commit();

    RateLimiter::log($agentId, 'neighbors', 200);
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'processed' => count($cleanNeighbors), 'new_unmanaged' => $newUnmanaged]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('neighbors.php error: ' . $e->getMessage());
    RateLimiter::log($agentId, 'neighbors', 500);
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur']);
}
