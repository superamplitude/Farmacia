<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo) return self::$pdo;
        $dsn = env('DB_DSN', 'sqlite:' . APP_ROOT . '/storage/farmacia.sqlite');
        $user = env('DB_USER', '');
        $pass = env('DB_PASS', '');
        if (str_starts_with($dsn, 'sqlite:')) {
            $file = substr($dsn, 7);
            $dir = dirname($file);
            if (!is_dir($dir)) mkdir($dir, 0770, true);
        }
        self::$pdo = new PDO($dsn, $user ?: null, $pass ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if (str_starts_with($dsn, 'sqlite:')) self::$pdo->exec('PRAGMA foreign_keys = ON');
        return self::$pdo;
    }
}
