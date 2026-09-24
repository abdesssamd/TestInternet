<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class LanNeighborModel
{
    /**
     * Upsert d'une MAC vue sur le LAN. Retourne si l'entree est nouvelle et si elle est geree (agent connu).
     *
     * @return array{is_new: bool, is_known: bool}
     */
    public static function upsert(string $mac, ?string $ip, int $seenByAgentId): array
    {
        $pdo = Database::getConnection();

        $existsStmt = $pdo->prepare('SELECT id FROM lan_neighbors WHERE mac = :mac LIMIT 1');
        $existsStmt->execute(['mac' => $mac]);
        $isNew = $existsStmt->fetch() === false;

        $isKnown = self::isKnownMac($mac);

        $stmt = $pdo->prepare(
            'INSERT INTO lan_neighbors (mac, last_ip, seen_by_agent_id, is_known, first_seen, last_seen, sightings_count)
             VALUES (:mac, :ip, :agent_id, :known, NOW(), NOW(), 1)
             ON DUPLICATE KEY UPDATE
                last_ip = VALUES(last_ip),
                seen_by_agent_id = VALUES(seen_by_agent_id),
                is_known = VALUES(is_known),
                last_seen = NOW(),
                sightings_count = sightings_count + 1'
        );
        $stmt->execute([
            'mac'      => $mac,
            'ip'       => $ip,
            'agent_id' => $seenByAgentId,
            'known'    => $isKnown ? 1 : 0,
        ]);

        return ['is_new' => $isNew, 'is_known' => $isKnown];
    }

    private static function isKnownMac(string $mac): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT 1 FROM agents WHERE primary_mac = :mac1
             UNION
             SELECT 1 FROM network_adapters WHERE mac = :mac2
             LIMIT 1'
        );
        $stmt->execute(['mac1' => $mac, 'mac2' => $mac]);
        return $stmt->fetch() !== false;
    }

    public static function listUnmanaged(int $limit = 200): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT ln.*, ag.hostname AS seen_by_hostname FROM lan_neighbors ln
             JOIN agents ag ON ag.id = ln.seen_by_agent_id
             WHERE ln.is_known = 0 AND ln.is_ignored = 0
             ORDER BY ln.last_seen DESC LIMIT :limit'
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function setIgnored(int $id, bool $ignored): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE lan_neighbors SET is_ignored = :ignored WHERE id = :id');
        $stmt->execute(['ignored' => $ignored ? 1 : 0, 'id' => $id]);
        return $stmt->rowCount() > 0;
    }
}
