<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $cfg = require BASE_PATH . '/config/database.php';

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['database'],
            $cfg['charset'],
        );

        try {
            self::$pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            // SET NAMES via exec() — o charset já vai no DSN, mas executamos
            // para garantir a collation utf8mb4_unicode_ci. Evita
            // PDO::MYSQL_ATTR_INIT_COMMAND (deprecated em PHP 8.5).
            self::$pdo->exec("SET NAMES {$cfg['charset']} COLLATE utf8mb4_unicode_ci");
            self::$pdo->exec("SET time_zone = '-03:00'");
        } catch (PDOException $e) {
            throw new RuntimeException('Falha ao conectar no banco: ' . $e->getMessage(), 0, $e);
        }

        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }
}
