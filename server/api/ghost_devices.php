<?php
/**
 * GET  /api/ghost_devices.php                 -> liste des appareils non geres detectes sur le LAN
 * POST /api/ghost_devices.php {action:"ignore", id, ignored:true|false} -> ecarter/reafficher une entree
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/Session.php';
require_once __DIR__ . '/../middleware/Csrf.php';
require_once __DIR__ . '/../models/LanNeighbor.php';

Session::start();
Session::requireAuthApi();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['devices' => LanNeighborModel::listUnmanaged()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode((string) $raw, true) ?: [];

    $csrfToken = $payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!Csrf::verify($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Jeton CSRF invalide']);
        exit;
    }

    $action = $payload['action'] ?? '';

    if ($action === 'ignore') {
        $id = (int) ($payload['id'] ?? 0);
        $ignored = !empty($payload['ignored']);
        $ok = $id > 0 && LanNeighborModel::setIgnored($id, $ignored);
        echo json_encode(['status' => $ok ? 'ok' : 'not_found']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Action inconnue']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Methode non autorisee']);
