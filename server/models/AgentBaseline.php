<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class AgentBaselineModel
{
    public static function forAgent(int $agentId): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM agent_baselines WHERE agent_id = :id LIMIT 1');
        $stmt->execute(['id' => $agentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Capture la baseline (MAC attendue) au premier contact d'un agent, sans ecraser
     * une baseline deja existante (INSERT IGNORE).
     */
    public static function ensureCaptured(int $agentId, ?string $mac): void
    {
        if (!$mac) {
            return;
        }
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO agent_baselines (agent_id, expected_mac, locked_at)
             VALUES (:agent_id, :mac, NOW())'
        );
        $stmt->execute(['agent_id' => $agentId, 'mac' => $mac]);
    }

    public static function update(int $agentId, ?string $mac, ?string $cidr): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO agent_baselines (agent_id, expected_mac, expected_cidr, locked_at)
             VALUES (:agent_id, :mac, :cidr, NOW())
             ON DUPLICATE KEY UPDATE expected_mac = VALUES(expected_mac), expected_cidr = VALUES(expected_cidr), locked_at = VALUES(locked_at)'
        );
        $stmt->execute(['agent_id' => $agentId, 'mac' => $mac, 'cidr' => $cidr]);
    }
}
