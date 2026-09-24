<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../middleware/Session.php';

final class AuthController
{
    public static function attemptLogin(string $username, string $password): bool
    {
        $user = UserModel::findByUsername($username);
        if (!$user || !$user['is_active']) {
            return false;
        }
        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }

        Session::regenerate();
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        UserModel::touchLogin((int) $user['id']);

        return true;
    }

    public static function logout(): void
    {
        Session::destroy();
    }
}
