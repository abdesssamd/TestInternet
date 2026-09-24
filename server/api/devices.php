<?php
/**
 * GET /api/devices.php
 * Liste des postes avec recherche/filtres/tri/pagination. Auth session requise.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/Session.php';
require_once __DIR__ . '/../models/Agent.php';

Session::start();
Session::requireAuthApi();

header('Content-Type: application/json; charset=utf-8');

$filters = [
    'search'   => trim((string) ($_GET['search'] ?? '')),
    'internet' => $_GET['internet'] ?? '',
    'wifi'     => $_GET['wifi'] ?? '',
    'ethernet' => $_GET['ethernet'] ?? '',
    'status'   => $_GET['status'] ?? '',
];

$sort = (string) ($_GET['sort'] ?? 'hostname');
$dir = (string) ($_GET['dir'] ?? 'ASC');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(200, max(1, (int) ($_GET['per_page'] ?? 25)));
$offset = ($page - 1) * $perPage;

$result = AgentModel::search($filters, $sort, $dir, $perPage, $offset);

$now = time();
$devices = array_map(static function (array $a) use ($now) {
    $lastSeen = $a['last_seen'] ? strtotime($a['last_seen']) : null;
    $online = $lastSeen !== null && ($now - $lastSeen) <= OFFLINE_THRESHOLD_SECONDS;
    return [
        'id'              => (int) $a['id'],
        'hostname'        => $a['hostname'],
        'primary_ip'      => $a['primary_ip'],
        'primary_mac'     => $a['primary_mac'],
        'current_user'    => $a['current_user'],
        'os_name'         => $a['os_name'],
        'ethernet_active' => (bool) $a['ethernet_active'],
        'wifi_active'     => (bool) $a['wifi_active'],
        'internet_status' => (bool) $a['internet_status'],
        'gateway'         => $a['gateway'],
        'last_seen'       => $a['last_seen'],
        'online'          => $online,
        'is_active'       => (bool) $a['is_active'],
    ];
}, $result['rows']);

echo json_encode([
    'devices'  => $devices,
    'total'    => $result['total'],
    'page'     => $page,
    'per_page' => $perPage,
]);
