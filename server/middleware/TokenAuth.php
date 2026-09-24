<?php
/**
 * Authentification des agents via header X-Agent-Token.
 * Le token n'est jamais stocke en clair : on compare son hash SHA-256
 * contre agents.token_hash (lui-meme issu de hash('sha256', $token)).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Agent.php';

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

    /**
     * Identification d'un agent sans token : le poste s'annonce par son nom
     * de machine (header X-Agent-Host) et est cree au premier contact.
     *
     * Le token reste accepte s'il est present, pour les postes deja
     * installes avec l'ancienne methode.
     *
     * @return array{agent: array<string,mixed>, enrolled: bool}
     */
    public static function resolveAgent(): array
    {
        // 1. Ancien mode : un token est fourni et valide -> on le respecte.
        $agent = self::authenticate();
        if ($agent !== null) {
            return ['agent' => $agent, 'enrolled' => false];
        }

        // 2. Mode auto : identification par nom de machine.
        $hostname = self::extractHostname();
        if ($hostname === null || $hostname === '') {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Nom de poste manquant (header X-Agent-Host)']);
            exit;
        }

        $existing = AgentModel::findByHostname($hostname);
        if ($existing) {
            if ((int) $existing['is_active'] !== 1) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Poste desactive par un administrateur']);
                exit;
            }
            return ['agent' => $existing, 'enrolled' => false];
        }

        // Premier contact : creation sous surveillance.
        $created = AgentModel::autoEnroll($hostname, self::clientIp());
        return ['agent' => $created, 'enrolled' => true];
    }

    public static function extractHostname(): ?string
    {
        foreach (self::getHeaders() as $name => $value) {
            if (strcasecmp($name, 'X-Agent-Host') === 0) {
                // Un nom NetBIOS/DNS ne contient pas d'autres caracteres :
                // on filtre pour ne pas laisser passer n'importe quoi en base.
                $clean = preg_replace('/[^A-Za-z0-9._-]/', '', trim($value));
                return $clean !== '' ? substr($clean, 0, 128) : null;
            }
        }
        return null;
    }

    public static function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return $ip !== null ? substr((string) $ip, 0, 45) : null;
    }
}
