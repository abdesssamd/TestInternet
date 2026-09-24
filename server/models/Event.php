<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class EventModel
{
    public static function log(int $agentId, string $type, string $message, ?array $details = null): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO events (agent_id, type, message, details) VALUES (:agent_id, :type, :message, :details)'
        );
        $stmt->execute([
            'agent_id' => $agentId,
            'type'     => $type,
            'message'  => $message,
            'details'  => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    public static function forAgent(int $agentId, int $limit = 100): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM events WHERE agent_id = :id ORDER BY created_at DESC LIMIT :limit');
        $stmt->bindValue('id', $agentId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function recent(int $limit = 50, ?string $type = null): array
    {
        $pdo = Database::getConnection();
        $sql = 'SELECT e.*, a.hostname FROM events e JOIN agents a ON a.id = e.agent_id';
        $params = [];
        if ($type) {
            $sql .= ' WHERE e.type = :type';
            $params['type'] = $type;
        }
        $sql .= ' ORDER BY e.created_at DESC LIMIT :limit';

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
