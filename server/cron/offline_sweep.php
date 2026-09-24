<?php
/**
 * offline_sweep.php
 * Script CLI a executer periodiquement (tache planifiee Windows, cote serveur)
 * pour detecter les agents devenus silencieux et emettre l'evenement AGENT_OFFLINE
 * + l'alerte AGENT_UNREACHABLE correspondante.
 *
 * Usage :
 *   php.exe server/cron/offline_sweep.php
 *
 * Planification recommandee : toutes les 2 minutes (voir INSTALLATION.md).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Ce script ne peut etre execute qu\'en ligne de commande (CLI).';
    exit(1);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Event.php';
require_once __DIR__ . '/../services/AlertEngine.php';

$pdo = Database::getConnection();

// Agents actifs, silencieux depuis plus de OFFLINE_THRESHOLD_SECONDS, pour lesquels
// aucun evenement AGENT_OFFLINE n'a deja ete emis depuis leur dernier contact
// (evite de re-emettre l'evenement/l'alerte a chaque execution du sweep).
$stmt = $pdo->prepare(
    "SELECT a.id, a.hostname, a.last_seen FROM agents a
     WHERE a.is_active = 1
       AND a.last_seen IS NOT NULL
       AND a.last_seen < (NOW() - INTERVAL :threshold SECOND)
       AND NOT EXISTS (
           SELECT 1 FROM events e
           WHERE e.agent_id = a.id AND e.type = 'AGENT_OFFLINE'
             AND e.created_at > a.last_seen
       )"
);
$stmt->execute(['threshold' => OFFLINE_THRESHOLD_SECONDS]);
$offlineAgents = $stmt->fetchAll();

$count = 0;
foreach ($offlineAgents as $row) {
    $agentId = (int) $row['id'];
    $hostname = $row['hostname'];

    EventModel::log($agentId, 'AGENT_OFFLINE', "$hostname ne repond plus depuis {$row['last_seen']}");
    AlertEngine::agentUnreachable($agentId, $hostname);
    $count++;
}

echo date('Y-m-d H:i:s') . " - offline_sweep : $count agent(s) marque(s) hors ligne." . PHP_EOL;
exit(0);
