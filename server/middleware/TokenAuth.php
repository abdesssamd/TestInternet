<?php
/**
 * Authentification des agents via header X-Agent-Token.
 * Le token n'est jamais stocke en clair : on compare son hash SHA-256
 * contre agents.token_hash (lui-meme issu de hash('sha256', $token)).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class TokenAuth
{
    /**
     * @return array<string,mixed>|null Enregistrement agent si valide, sinon null.
     */
    public static function authenticate(): ?array
    {
        $token = self::extractToken();
        if ($token === null || $token === '') {
            return null;
        }

        $tokenHash = hash('sha256', $token);

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM agents WHERE token_hash = :hash LIMIT 1');
        $stmt->execute(['hash' => $tokenHash]);
        $agent = $stmt->fetch();

        if (!$agent) {
            return null;
        }
        if ((int) $agent['is_active'] !== 1) {
            return null;
        }

        return $agent;
    }

    public static function extractToken(): ?string
    {
        $headers = self::getHeaders();
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'X-Agent-Token') === 0) {
                return trim($value);
            }
        }
        return null;
    }

    private static function getHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $h = getallheaders();
            if ($h !== false) {
                return $h;
            }
        }
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    public static function requireValid(): array
    {
        $agent = self::authenticate();
        if ($agent === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Token agent invalide ou agent desactive']);
            exit;
        }
        return $agent;
    }
}
