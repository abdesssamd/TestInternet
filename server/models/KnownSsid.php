<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class KnownSsidModel
{
    /**
     * @return string[]
     */
    public static function all(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query('SELECT ssid FROM known_ssids ORDER BY ssid');
        return array_column($stmt->fetchAll(), 'ssid');
    }

    public static function listWithIds(): array
    {
        $pdo = Database::getConnection();
        return $pdo->query('SELECT id, ssid FROM known_ssids ORDER BY ssid')->fetchAll();
    }

    public static function add(string $ssid): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('INSERT IGNORE INTO known_ssids (ssid) VALUES (:ssid)');
        $stmt->execute(['ssid' => $ssid]);
    }

    public static function remove(int $id): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM known_ssids WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
