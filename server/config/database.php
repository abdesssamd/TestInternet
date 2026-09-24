<?php
/**
 * Fournit une instance PDO unique (singleton) vers MySQL/MariaDB.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

final class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_CHARSET
            );

            try {
                self::$instance = new PDO(DB_USER ? $dsn : $dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);

                // Aligne le fuseau horaire de la session MySQL sur celui de PHP
                // (evite les ecarts entre NOW() cote SQL et time() cote PHP).
                $offset = (new DateTime('now', new DateTimeZone(APP_TIMEZONE)))->format('P');
                self::$instance->exec("SET time_zone = '" . $offset . "'");
            } catch (PDOException $e) {
                error_log('DB connection failed: ' . $e->getMessage());
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Database connection failed']);
                exit;
            }
        }

        return self::$instance;
    }
}
