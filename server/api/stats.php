<?php
/**
 * GET /api/stats.php
 * Agregats pour les cartes du dashboard + serie temporelle pour Chart.js.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/Session.php';
require_once __DIR__ . '/../models/Agent.php';
require_once __DIR__ . '/../config/database.php';

Session::start();
Session::requireAuthApi();

header('Content-Type: application/json; charset=utf-8');

$stats = AgentModel::stats();

$pdo = Database::getConnection();
$series = $pdo->query(
    "SELECT
        DATE_FORMAT(recorded_at, '%Y-%m-%d %H:%i:00') AS bucket,
        SUM(internet_status) AS internet_count,
        COUNT(DISTINCT agent_id) AS agent_count
     FROM network_history
     WHERE recorded_at >= (NOW() - INTERVAL 6 HOUR)
     GROUP BY bucket
     ORDER BY bucket ASC"
)->fetchAll();

echo json_encode([
    'stats'  => $stats,
    'series' => $series,
]);
