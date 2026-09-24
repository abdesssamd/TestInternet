<?php
/**
 * GET  /api/alerts.php?unread=1        -> liste des alertes
 * POST /api/alerts.php  {action:"mark_read", id:123}  -> marquer comme lu
 * POST /api/alerts.php  {action:"mark_all_read"}       -> tout marquer lu
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/Session.php';
require_once __DIR__ . '/../middleware/Csrf.php';
require_once __DIR__ . '/../models/Alert.php';

Session::start();
Session::requireAuthApi();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $unreadOnly = !empty($_GET['unread']);
    $limit = min(500, max(1, (int) ($_GET['limit'] ?? 100)));
    echo json_encode(['alerts' => AlertModel::list($unreadOnly, $limit)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode((string) $raw, true) ?: [];

    // CSRF: le dashboard doit envoyer le token recu au chargement de la page
    $csrfToken = $payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!Csrf::verify($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Jeton CSRF invalide']);
        exit;
    }

    $action = $payload['action'] ?? '';

    if ($action === 'mark_read') {
        $id = (int) ($payload['id'] ?? 0);
        $ok = $id > 0 && AlertModel::markRead($id);
        echo json_encode(['status' => $ok ? 'ok' : 'not_found']);
        exit;
    }

    if ($action === 'mark_all_read') {
        AlertModel::markAllRead();
        echo json_encode(['status' => 'ok']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Action inconnue']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Methode non autorisee']);
