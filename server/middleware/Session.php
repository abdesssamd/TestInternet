<?php
/**
 * Demarrage securise de la session PHP dashboard.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => APP_BASE_PATH . '/',
            'domain'   => '',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();

        // Expiration par inactivite
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME_SECONDS) {
            self::destroy();
            session_start();
        }
        $_SESSION['last_activity'] = time();
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function isAuthenticated(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    public static function requireAuth(): void
    {
        if (!self::isAuthenticated()) {
            header('Location: ' . APP_BASE_PATH . '/dashboard/login.php');
            exit;
        }
    }

    public static function requireAuthApi(): void
    {
        if (!self::isAuthenticated()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Non authentifie']);
            exit;
        }
    }
}
