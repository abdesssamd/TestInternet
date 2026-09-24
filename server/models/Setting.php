<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class SettingModel
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = :k LIMIT 1');
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch();
        return $row ? $row['setting_value'] : $default;
    }

    public static function getInt(string $key, int $default): int
    {
        $value = self::get($key);
        return $value === null || $value === '' ? $default : (int) $value;
    }

    public static function getBool(string $key, bool $default): bool
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return $default;
        }
        return $value === '1' || strtolower($value) === 'true';
    }

    public static function set(string $key, string $value): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute(['k' => $key, 'v' => $value]);
    }

    /**
     * @return array<string,string>
     */
    public static function all(): array
    {
        $pdo = Database::getConnection();
        $rows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[$row['setting_key']] = $row['setting_value'];
        }
        return $result;
    }
}
