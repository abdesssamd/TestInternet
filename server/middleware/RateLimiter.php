<?php
/**
 * Limite le nombre de rapports qu'un agent peut envoyer (anti-flood).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';

final class RateLimiter
{
    public static function tooManyRequests(int $agentId, string $endpoint = 'report', int $windowSeconds = API_RATE_LIMIT_SECONDS): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS cnt FROM api_logs
             WHERE agent_id = :agent_id
               AND endpoint = :endpoint
               AND created_at >= (NOW() - INTERVAL :seconds SECOND)'
        );
        $stmt->execute([
            'agent_id' => $agentId,
            'endpoint' => $endpoint,
            'seconds'  => $windowSeconds,
        ]);
        $row = $stmt->fetch();

        return ((int) $row['cnt']) > 0;
    }

    public static function log(?int $agentId, string $endpoint, int $statusCode): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO api_logs (agent_id, endpoint, ip_address, status_code)
             VALUES (:agent_id, :endpoint, :ip, :status)'
        );
        $stmt->execute([
            'agent_id' => $agentId,
            'endpoint' => $endpoint,
            'ip'       => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'status'   => $statusCode,
        ]);
    }
}
