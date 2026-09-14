<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo) return self::$pdo;

        $dsn = (string)env('DB_DSN', 'sqlite:' . APP_ROOT . '/storage/farmacia.sqlite');
        $user = (string)env('DB_USER', '');
        $pass = (string)env('DB_PASS', '');
        $isSqlite = str_starts_with($dsn, 'sqlite:');

        if ($isSqlite) {
            $file = substr($dsn, 7);
            $dir = dirname($file);
            if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
                throw new RuntimeException('Não foi possível criar o diretório do SQLite: ' . $dir);
            }
        }

        self::$pdo = new PDO($dsn, $user !== '' ? $user : null, $pass !== '' ? $pass : null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        if ($isSqlite) {
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA busy_timeout = 5000');
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA synchronous = NORMAL');
        }

        return self::$pdo;
    }
}
