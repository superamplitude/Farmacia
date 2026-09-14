<?php
declare(strict_types=1);

final class Auth
{
    public static function bootstrapAdmin(PDO $db): void
    {
        $email = trim((string)env('ADMIN_EMAIL', ''));
        $password = (string)env('ADMIN_PASSWORD', '');
        if ($email === '' || $password === '') return;
        $st = $db->prepare('SELECT id FROM users WHERE email = ?');
        $st->execute([$email]);
        if (!$st->fetch()) {
            $ins = $db->prepare('INSERT INTO users(email,password_hash,role) VALUES(?,?,?)');
            $ins->execute([$email, password_hash($password, PASSWORD_DEFAULT), 'admin']);
        }
    }

    public static function login(PDO $db, string $email, string $password): bool
    {
        $st = $db->prepare('SELECT * FROM users WHERE email = ? AND active = 1 LIMIT 1');
        $st->execute([$email]);
        $u = $st->fetch();
        if (!$u || !password_verify($password, $u['password_hash'])) return false;
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$u['id'];
        $_SESSION['admin_email'] = $u['email'];
        return true;
    }

    public static function check(): bool { return !empty($_SESSION['admin_id']); }
    public static function require(): void { if (!self::check()) { header('Location: ' . url('admin.php')); exit; } }
    public static function logout(): void { $_SESSION = []; session_destroy(); }
}
