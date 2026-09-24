<?php
/**
 * Protection CSRF pour les formulaires du dashboard.
 */

declare(strict_types=1);

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    public static function verify(?string $token): bool
    {
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function requireValid(): void
    {
        $token = $_POST['csrf_token'] ?? '';
        if (!self::verify($token)) {
            http_response_code(403);
            die('Requete refusee : jeton CSRF invalide.');
        }
    }
}
