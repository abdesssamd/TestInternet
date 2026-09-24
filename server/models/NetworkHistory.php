<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class NetworkHistoryModel
{
    public static function insertSnapshot(int $agentId, bool $internet, ?int $latency, bool $ethernet, bool $wifi): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO network_history (agent_id, internet_status, internet_latency, ethernet_active, wifi_active)
             VALUES (:agent_id, :internet, :latency, :eth, :wifi)'
        );
        $stmt->execute([
            'agent_id' => $agentId,
            'internet' => $internet ? 1 : 0,
            'latency'  => $latency,
            'eth'      => $ethernet ? 1 : 0,
            'wifi'     => $wifi ? 1 : 0,
        ]);
    }

    public static function forAgent(int $agentId, int $limit = 100): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT internet_status, internet_latency, recorded_at FROM network_history
             WHERE agent_id = :id ORDER BY recorded_at DESC LIMIT :limit'
        );
        $stmt->bindValue('id', $agentId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_reverse($stmt->fetchAll());
    }

    /**
     * Disponibilite (%) d'un poste sur une fenetre glissante de $hours heures,
     * basee sur la proportion de rapports ou Internet/LAN etait actif.
     *
     * @return array{total_samples:int, internet_up_samples:int, lan_up_samples:int, internet_uptime_pct:float, lan_uptime_pct:float}
     */
    public static function availability(int $agentId, int $hours): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT
                COUNT(*) AS total_samples,
                COALESCE(SUM(internet_status), 0) AS internet_up_samples,
                COALESCE(SUM(CASE WHEN ethernet_active = 1 OR wifi_active = 1 THEN 1 ELSE 0 END), 0) AS lan_up_samples
             FROM network_history
             WHERE agent_id = :agent_id AND recorded_at >= (NOW() - INTERVAL :hours HOUR)'
        );
        $stmt->execute(['agent_id' => $agentId, 'hours' => $hours]);
        $row = $stmt->fetch();

        $total = (int) $row['total_samples'];
        $internetUp = (int) $row['internet_up_samples'];
        $lanUp = (int) $row['lan_up_samples'];

        return [
            'total_samples'       => $total,
            'internet_up_samples' => $internetUp,
            'lan_up_samples'      => $lanUp,
            'internet_uptime_pct' => $total > 0 ? round($internetUp / $total * 100, 1) : 0.0,
            'lan_uptime_pct'      => $total > 0 ? round($lanUp / $total * 100, 1) : 0.0,
        ];
    }

    /**
     * Disponibilite (%) de tous les agents sur une fenetre glissante de $hours heures.
     */
    public static function availabilityAllAgents(int $hours): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT
                ag.id AS agent_id,
                ag.hostname,
                COUNT(nh.id) AS total_samples,
                COALESCE(SUM(nh.internet_status), 0) AS internet_up_samples,
                COALESCE(SUM(CASE WHEN nh.ethernet_active = 1 OR nh.wifi_active = 1 THEN 1 ELSE 0 END), 0) AS lan_up_samples
             FROM agents ag
             LEFT JOIN network_history nh
                ON nh.agent_id = ag.id AND nh.recorded_at >= (NOW() - INTERVAL :hours HOUR)
             GROUP BY ag.id, ag.hostname
             ORDER BY ag.hostname'
        );
        $stmt->execute(['hours' => $hours]);
        $rows = $stmt->fetchAll();

        return array_map(static function (array $row): array {
            $total = (int) $row['total_samples'];
            $internetUp = (int) $row['internet_up_samples'];
            $lanUp = (int) $row['lan_up_samples'];
            return [
                'agent_id'            => (int) $row['agent_id'],
                'hostname'            => $row['hostname'],
                'total_samples'       => $total,
                'internet_uptime_pct' => $total > 0 ? round($internetUp / $total * 100, 1) : 0.0,
                'lan_uptime_pct'      => $total > 0 ? round($lanUp / $total * 100, 1) : 0.0,
            ];
        }, $rows);
    }
}
