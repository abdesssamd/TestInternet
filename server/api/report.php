<?php
/**
 * POST /api/report.php
 * Point d'entree utilise par les agents Windows pour envoyer leur etat reseau.
 * Auth: header X-Agent-Token
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/TokenAuth.php';
require_once __DIR__ . '/../middleware/RateLimiter.php';
require_once __DIR__ . '/../models/Agent.php';
require_once __DIR__ . '/../models/NetworkAdapter.php';
require_once __DIR__ . '/../models/NetworkHistory.php';
require_once __DIR__ . '/../models/Event.php';
require_once __DIR__ . '/../models/AgentBaseline.php';
require_once __DIR__ . '/../models/KnownSsid.php';
require_once __DIR__ . '/../models/Setting.php';
require_once __DIR__ . '/../services/DualConnectionDetector.php';
require_once __DIR__ . '/../services/AnomalyDetector.php';
require_once __DIR__ . '/../services/AlertEngine.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Methode non autorisee']);
    exit;
}

// --- Identification agent ---
// Sans token : le poste s'annonce par son nom de machine et est enregistre
// automatiquement au premier contact, sous surveillance par defaut.
$resolved = TokenAuth::resolveAgent();
$agent = $resolved['agent'];
$agentId = (int) $agent['id'];
$hostname = $agent['hostname'];

if ($resolved['enrolled']) {
    EventModel::log(
        $agentId,
        'AGENT_AUTO_ENROLLED',
        "$hostname detecte automatiquement (" . (TokenAuth::clientIp() ?? 'IP inconnue') . ") et place sous surveillance"
    );
}

// --- Rate limiting ---
if (RateLimiter::tooManyRequests($agentId)) {
    RateLimiter::log($agentId, 'report', 429);
    http_response_code(429);
    echo json_encode(['error' => 'Trop de requetes, reessayez plus tard']);
    exit;
}

// --- Lecture et validation du payload ---
$raw = file_get_contents('php://input', false, null, 0, API_MAX_PAYLOAD_BYTES + 1);
if ($raw === false || strlen($raw) > API_MAX_PAYLOAD_BYTES) {
    RateLimiter::log($agentId, 'report', 413);
    http_response_code(413);
    echo json_encode(['error' => 'Payload trop volumineux']);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    RateLimiter::log($agentId, 'report', 400);
    http_response_code(400);
    echo json_encode(['error' => 'JSON invalide']);
    exit;
}

$adapters = $payload['adapters'] ?? [];
if (!is_array($adapters)) {
    $adapters = [];
}

$validTypes = ['ETHERNET', 'WIFI', 'OTHER'];
$validStatus = ['UP', 'DOWN'];
$cleanAdapters = [];
foreach ($adapters as $a) {
    if (!is_array($a) || empty($a['name'])) {
        continue;
    }
    $cleanAdapters[] = [
        'name'    => substr((string) $a['name'], 0, 128),
        'type'    => in_array($a['type'] ?? '', $validTypes, true) ? $a['type'] : 'OTHER',
        'status'  => in_array($a['status'] ?? '', $validStatus, true) ? $a['status'] : 'DOWN',
        'ipv4'    => isset($a['ipv4']) ? substr((string) $a['ipv4'], 0, 45) : null,
        'mac'     => isset($a['mac']) ? substr((string) $a['mac'], 0, 17) : null,
        'gateway' => isset($a['gateway']) ? substr((string) $a['gateway'], 0, 45) : null,
        'ssid'    => isset($a['ssid']) ? substr((string) $a['ssid'], 0, 128) : null,
    ];
}

$reportData = [
    'os_name'             => isset($payload['os_name']) ? substr((string) $payload['os_name'], 0, 128) : null,
    'os_version'          => isset($payload['os_version']) ? substr((string) $payload['os_version'], 0, 64) : null,
    'current_user'        => isset($payload['current_user']) ? substr((string) $payload['current_user'], 0, 128) : null,
    'domain'               => isset($payload['domain']) ? substr((string) $payload['domain'], 0, 128) : null,
    'primary_ip'          => isset($payload['primary_ip']) ? substr((string) $payload['primary_ip'], 0, 45) : null,
    'primary_mac'         => isset($payload['primary_mac']) ? substr((string) $payload['primary_mac'], 0, 17) : null,
    'gateway'             => isset($payload['gateway']) ? substr((string) $payload['gateway'], 0, 45) : null,
    'ethernet_active'     => !empty($payload['ethernet_active']),
    'wifi_active'         => !empty($payload['wifi_active']),
    'wifi_ssid'           => isset($payload['wifi_ssid']) ? substr((string) $payload['wifi_ssid'], 0, 128) : null,
    'internet_status'     => !empty($payload['internet_status']),
    'internet_test'       => isset($payload['internet_test']) ? substr(json_encode($payload['internet_test']), 0, 255) : null,
    'internet_latency'    => isset($payload['internet_latency']) ? (int) $payload['internet_latency'] : null,
    'internet_checked_at' => isset($payload['internet_checked_at']) ? substr((string) $payload['internet_checked_at'], 0, 25) : null,
    'agent_version'       => isset($payload['agent_version']) ? substr((string) $payload['agent_version'], 0, 32) : null,
];

$pdo = Database::getConnection();

try {
    $pdo->beginTransaction();

    $previousAgentState = AgentModel::findById($agentId);
    $previousAdapters = NetworkAdapterModel::forAgent($agentId);

    AgentModel::updateFromReport($agentId, $reportData);
    $diff = NetworkAdapterModel::sync($agentId, $cleanAdapters);

    // --- Snapshot historique ---
    NetworkHistoryModel::insertSnapshot(
        $agentId,
        $reportData['internet_status'],
        $reportData['internet_latency'],
        $reportData['ethernet_active'],
        $reportData['wifi_active']
    );

    // --- Generation d'evenements par diff avec l'etat precedent ---
    $wasWifiActive = !empty($previousAgentState['wifi_active']);
    $wasInternetUp = !empty($previousAgentState['internet_status']);
    $prevIp = $previousAgentState['primary_ip'] ?? null;
    $prevGateway = $previousAgentState['gateway'] ?? null;

    if ($reportData['wifi_active'] && !$wasWifiActive) {
        EventModel::log($agentId, 'WIFI_CONNECTED', "$hostname a active le Wi-Fi" . ($reportData['wifi_ssid'] ? " ($reportData[wifi_ssid])" : ''));
        AlertEngine::wifiActivated($agentId, $hostname);
    } elseif (!$reportData['wifi_active'] && $wasWifiActive) {
        EventModel::log($agentId, 'WIFI_DISCONNECTED', "$hostname a desactive le Wi-Fi");
    }

    if ($reportData['internet_status'] && !$wasInternetUp) {
        EventModel::log($agentId, 'INTERNET_ON', "$hostname a retrouve l'acces Internet");
        AlertEngine::internetDetected($agentId, $hostname);
    } elseif (!$reportData['internet_status'] && $wasInternetUp) {
        EventModel::log($agentId, 'INTERNET_OFF', "$hostname a perdu l'acces Internet");
    }

    if ($prevIp !== null && $reportData['primary_ip'] !== null && $prevIp !== $reportData['primary_ip']) {
        EventModel::log($agentId, 'IP_CHANGED', "$hostname : IP changee de $prevIp vers {$reportData['primary_ip']}");
    }
    if ($prevGateway !== null && $reportData['gateway'] !== null && $prevGateway !== $reportData['gateway']) {
        EventModel::log($agentId, 'GATEWAY_CHANGED', "$hostname : passerelle changee de $prevGateway vers {$reportData['gateway']}");
    }

    foreach ($diff['added'] as $name) {
        EventModel::log($agentId, 'INTERFACE_ADDED', "$hostname : nouvelle interface detectee ($name)");
    }
    foreach ($diff['removed'] as $name) {
        EventModel::log($agentId, 'INTERFACE_REMOVED', "$hostname : interface disparue ($name)");
    }

    $isFirstContact = empty($previousAgentState['last_seen']);
    if ($isFirstContact) {
        EventModel::log($agentId, 'AGENT_ONLINE', "$hostname s'est connecte pour la premiere fois");
        // Auto-apprentissage de la baseline (MAC attendue) au premier contact.
        AgentBaselineModel::ensureCaptured($agentId, $reportData['primary_mac']);
    }

    // --- Detection double connexion ---
    $analysis = DualConnectionDetector::analyze($cleanAdapters, $reportData['internet_status']);
    if ($analysis['isDual']) {
        AlertEngine::dualConnection($agentId, $hostname, $analysis['activeAdapters']);
    }

    // --- Detection d'anomalies reseau (MAC/IP/SSID inattendus) ---
    if (!$isFirstContact && SettingModel::getBool('anomaly_detection_enabled', true)) {
        $baseline = AgentBaselineModel::forAgent($agentId);
        $anomalyResult = AnomalyDetector::analyze(
            $baseline,
            $reportData,
            KnownSsidModel::all(),
            SettingModel::get('anomaly_default_cidr')
        );
        foreach ($anomalyResult['anomalies'] as $anomaly) {
            EventModel::log($agentId, 'NETWORK_ANOMALY_DETECTED', "$hostname : {$anomaly['message']}", $anomaly['details']);
            AlertEngine::networkAnomaly($agentId, $hostname, $anomaly['severity'], $anomaly['message'], $anomaly['details']);
        }
    }

    // --- Coupure Internet a distance -------------------------------------
    // L'agent envoie l'etat qu'il applique reellement ; on ne journalise que
    // les transitions, sinon chaque rapport (toutes les 60 s) creerait un
    // evenement. La consigne est ensuite renvoyee dans la reponse : c'est
    // ainsi que l'ordre descend jusqu'au poste, sans ouvrir de port sur lui.
    $blockState = AgentModel::findById($agentId);
    $wanted = (bool) ($blockState['internet_blocked'] ?? false);

    if (array_key_exists('internet_block_applied', $payload)) {
        $applied = (bool) $payload['internet_block_applied'];
        if (AgentModel::markInternetBlockApplied($agentId, $applied)) {
            EventModel::log(
                $agentId,
                $applied ? 'INTERNET_BLOCK_APPLIED' : 'INTERNET_BLOCK_RELEASED',
                $applied
                    ? "$hostname : acces Internet coupe par le serveur (LAN conserve)"
                    : "$hostname : acces Internet retabli"
            );
        }

        // --- Coherence declaratif / mesure -------------------------------
        // Le poste pretend appliquer la coupure, mais ses propres tests de
        // connectivite reussissent : les deux ne peuvent pas etre vrais en
        // meme temps. Soit les regles de pare-feu ont ete retirees en local,
        // soit l'agent a ete altere pour mentir. Dans les deux cas la
        // supervision n'est plus fiable pour ce poste : on alerte.
        if ($wanted && $applied && !empty($reportData['internet_status'])) {
            EventModel::log(
                $agentId,
                'INTERNET_BLOCK_BYPASSED',
                "$hostname : blocage declare actif mais Internet toujours accessible"
            );
            AlertEngine::internetBlockBypassed($agentId, $hostname, [
                'declare'          => 'blocage applique',
                'mesure'           => 'acces Internet fonctionnel',
                'latence_ms'       => $reportData['internet_latency'],
                'teste_a'          => $reportData['internet_checked_at'],
            ]);
        }
    }

    // Le poste devrait etre coupe mais ne confirme rien depuis longtemps :
    // l'agent a pu etre desactive ou supprime apres la pose de la consigne.
    if ($wanted && !array_key_exists('internet_block_applied', $payload)) {
        EventModel::log(
            $agentId,
            'INTERNET_BLOCK_BYPASSED',
            "$hostname : agent sans support du blocage (version obsolete ou altere)"
        );
        AlertEngine::internetBlockBypassed($agentId, $hostname, [
            'declare' => 'aucun etat de blocage remonte',
            'mesure'  => 'consigne de coupure active cote serveur',
        ]);
    }

    $pdo->commit();

    RateLimiter::log($agentId, 'report', 200);
    http_response_code(200);
    echo json_encode([
        'status' => 'ok',
        // Consigne a appliquer par l'agent des ce cycle.
        'commands' => ['block_internet' => $wanted],
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('report.php error: ' . $e->getMessage());
    RateLimiter::log($agentId, 'report', 500);
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur']);
}
