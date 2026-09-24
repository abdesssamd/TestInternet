<?php
/**
 * POST /api/agents_admin.php
 * Actions d'administration sur les agents (creation, activation/desactivation).
 * Reserve aux sessions dashboard authentifiees + CSRF.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/Session.php';
require_once __DIR__ . '/../middleware/Csrf.php';
require_once __DIR__ . '/../models/Agent.php';
require_once __DIR__ . '/../models/Event.php';

Session::start();
Session::requireAuthApi();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Methode non autorisee']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode((string) $raw, true) ?: [];

$csrfToken = $payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!Csrf::verify($csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Jeton CSRF invalide']);
    exit;
}

$action = $payload['action'] ?? '';

if ($action === 'create') {
    $hostname = trim((string) ($payload['hostname'] ?? ''));
    if ($hostname === '' || strlen($hostname) > 128) {
        http_response_code(400);
        echo json_encode(['error' => 'Nom de poste invalide']);
        exit;
    }
    if (AgentModel::findByHostname($hostname)) {
        http_response_code(409);
        echo json_encode(['error' => 'Ce poste existe deja']);
        exit;
    }
    $result = AgentModel::create($hostname);
    // Le token en clair n'est retourne qu'une seule fois, a la creation.
    echo json_encode(['status' => 'ok', 'id' => $result['id'], 'token' => $result['token']]);
    exit;
}

if ($action === 'set_active') {
    $id = (int) ($payload['id'] ?? 0);
    $active = !empty($payload['active']);
    if ($id <= 0 || !AgentModel::findById($id)) {
        http_response_code(404);
        echo json_encode(['error' => 'Agent introuvable']);
        exit;
    }
    AgentModel::setActive($id, $active);
    echo json_encode(['status' => 'ok']);
    exit;
}

if ($action === 'set_internet_blocked') {
    $id = (int) ($payload['id'] ?? 0);
    $blocked = !empty($payload['blocked']);
    $agent = $id > 0 ? AgentModel::findById($id) : null;

    if (!$agent) {
        http_response_code(404);
        echo json_encode(['error' => 'Agent introuvable']);
        exit;
    }

    $by = (string) ($_SESSION['username'] ?? 'inconnu');
    AgentModel::setInternetBlocked($id, $blocked, $by);

    // Tracer la demande des maintenant : l'application effective sera
    // journalisee separement quand l'agent aura confirme.
    EventModel::log(
        $id,
        $blocked ? 'INTERNET_BLOCK_REQUESTED' : 'INTERNET_UNBLOCK_REQUESTED',
        ($blocked ? 'Coupure Internet demandee' : 'Retablissement Internet demande')
            . " pour {$agent['hostname']} par $by"
    );

    echo json_encode([
        'status'  => 'ok',
        'blocked' => $blocked,
        // L'ordre ne part qu'au prochain contact de l'agent : l'interface
        // doit le dire clairement plutot que d'afficher un succes immediat.
        'message' => $blocked
            ? "Coupure demandee. Elle sera appliquee au prochain rapport de l'agent."
            : "Retablissement demande. Il sera applique au prochain rapport de l'agent.",
    ]);
    exit;
}

if ($action === 'set_monitored') {
    $id = (int) ($payload['id'] ?? 0);
    $monitored = !empty($payload['monitored']);
    $agent = $id > 0 ? AgentModel::findById($id) : null;

    if (!$agent) {
        http_response_code(404);
        echo json_encode(['error' => 'Agent introuvable']);
        exit;
    }

    $by = (string) ($_SESSION['username'] ?? 'inconnu');
    AgentModel::setMonitored($id, $monitored, $by);

    EventModel::log(
        $id,
        $monitored ? 'MONITORING_ENABLED' : 'MONITORING_DISABLED',
        ($monitored ? 'Surveillance activee' : 'Surveillance retiree')
            . " pour {$agent['hostname']} par $by"
    );

    echo json_encode([
        'status'    => 'ok',
        'monitored' => $monitored,
        'message'   => $monitored
            ? 'Poste remis sous surveillance.'
            : 'Poste retire de la surveillance.',
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Action inconnue']);
