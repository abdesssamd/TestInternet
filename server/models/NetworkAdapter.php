<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class NetworkAdapterModel
{
    public static function forAgent(int $agentId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM network_adapters WHERE agent_id = :id ORDER BY type, name');
        $stmt->execute(['id' => $agentId]);
        return $stmt->fetchAll();
    }

    /**
     * Remplace l'etat des interfaces d'un agent par la liste fournie.
     * Retourne les differences (ajouts/suppressions) pour la generation d'evenements.
     *
     * @param array<int,array<string,mixed>> $adapters
     * @return array{added: array, removed: array}
     */
    public static function sync(int $agentId, array $adapters): array
    {
        $pdo = Database::getConnection();

        $existingStmt = $pdo->prepare('SELECT name FROM network_adapters WHERE agent_id = :id');
        $existingStmt->execute(['id' => $agentId]);
        $existingNames = array_column($existingStmt->fetchAll(), 'name');

        $incomingNames = array_column($adapters, 'name');

        $added = array_diff($incomingNames, $existingNames);
        $removed = array_diff($existingNames, $incomingNames);

        $upsert = $pdo->prepare(
            'INSERT INTO network_adapters (agent_id, type, name, status, ipv4, mac, gateway, ssid)
             VALUES (:agent_id, :type, :name, :status, :ipv4, :mac, :gateway, :ssid)
             ON DUPLICATE KEY UPDATE
                type = VALUES(type), status = VALUES(status), ipv4 = VALUES(ipv4),
                mac = VALUES(mac), gateway = VALUES(gateway), ssid = VALUES(ssid)'
        );

        foreach ($adapters as $a) {
            $upsert->execute([
                'agent_id' => $agentId,
                'type'     => $a['type'] ?? 'OTHER',
                'name'     => $a['name'] ?? 'unknown',
                'status'   => $a['status'] ?? 'DOWN',
                'ipv4'     => $a['ipv4'] ?? null,
                'mac'      => $a['mac'] ?? null,
                'gateway'  => $a['gateway'] ?? null,
                'ssid'     => $a['ssid'] ?? null,
            ]);
        }

        if (!empty($removed)) {
            $placeholders = implode(',', array_fill(0, count($removed), '?'));
            $del = $pdo->prepare("DELETE FROM network_adapters WHERE agent_id = ? AND name IN ($placeholders)");
            $del->execute(array_merge([$agentId], array_values($removed)));
        }

        return ['added' => array_values($added), 'removed' => array_values($removed)];
    }
}
