<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class AlertModel
{
    public static function create(int $agentId, string $type, string $severity, string $message, ?array $details = null): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO alerts (agent_id, type, severity, message, details) VALUES (:agent_id, :type, :severity, :message, :details)'
        );
        $stmt->execute([
            'agent_id' => $agentId,
            'type'     => $type,
            'severity' => $severity,
            'message'  => $message,
            'details'  => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Marque qu'une notification email a ete envoyee pour cette alerte (throttle).
     */
    public static function markNotified(int $id): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE alerts SET notified_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Une notification email a-t-elle deja ete envoyee recemment pour ce type d'alerte sur cet agent ?
     */
    public static function recentlyNotified(int $agentId, string $type, int $withinSeconds): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS cnt FROM alerts
             WHERE agent_id = :agent_id AND type = :type
               AND notified_at IS NOT NULL
               AND notified_at >= (NOW() - INTERVAL :s SECOND)'
        );
        $stmt->execute(['agent_id' => $agentId, 'type' => $type, 's' => $withinSeconds]);
        return ((int) $stmt->fetch()['cnt']) > 0;
    }

    public static function list(bool $unreadOnly = false, int $limit = 100): array
    {
        $pdo = Database::getConnection();
        $sql = 'SELECT al.*, ag.hostname FROM alerts al JOIN agents ag ON ag.id = al.agent_id';
        if ($unreadOnly) {
            $sql .= ' WHERE al.is_read = 0';
        }
        $sql .= ' ORDER BY al.created_at DESC LIMIT :limit';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function markRead(int $id): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE alerts SET is_read = 1, read_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public static function markAllRead(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec('UPDATE alerts SET is_read = 1, read_at = NOW() WHERE is_read = 0');
    }

    /**
     * Evite les doublons d'alertes recentes du meme type pour le meme agent.
     */
    public static function existsRecent(int $agentId, string $type, int $withinSeconds = 300): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS cnt FROM alerts
             WHERE agent_id = :agent_id AND type = :type
               AND created_at >= (NOW() - INTERVAL :s SECOND)'
        );
        $stmt->execute(['agent_id' => $agentId, 'type' => $type, 's' => $withinSeconds]);
        return ((int) $stmt->fetch()['cnt']) > 0;
    }
}
