<?php
declare(strict_types=1);

final class Auth
{
    public static function bootstrapAdmin(PDO $db): void
    {
        $email=trim((string)env('SUPERADMIN_EMAIL',env('ADMIN_EMAIL',''))); $password=(string)env('SUPERADMIN_PASSWORD',env('ADMIN_PASSWORD',''));
        if($email===''||$password==='')return;
        $st=$db->prepare('SELECT id FROM users WHERE email=?');$st->execute([$email]);
        if(!$st->fetch()){$ins=$db->prepare('INSERT INTO users(email,password_hash,name,role) VALUES(?,?,?,?)');$ins->execute([$email,password_hash($password,PASSWORD_DEFAULT),'Super Admin','super_admin']);}
    }

    public static function bootstrapStoreAdmin(PDO $db, int $pharmacyId): void
    {
        if ($pharmacyId <= 0) return;
        $st = $db->prepare("SELECT id FROM users WHERE pharmacy_id=? AND role='pharmacy_admin' LIMIT 1");
        $st->execute([$pharmacyId]);
        if ($st->fetch()) return;

        $email = trim((string)env('PHARMACY_ADMIN_EMAIL', 'admin-farmacia@superamplitude.com'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $email = 'admin-farmacia@superamplitude.com';

        $collision = $db->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
        $collision->execute([$email]);
        if ($collision->fetch()) $email = 'admin-farmacia-' . $pharmacyId . '@superamplitude.com';

        $password = (string)env('PHARMACY_ADMIN_PASSWORD', '');
        $generated = false;
        if (strlen($password) < 14) {
            $password = 'F4rmAdm!' . bin2hex(random_bytes(14));
            $generated = true;
        }

        $p = $db->prepare('SELECT name FROM pharmacies WHERE id=? LIMIT 1');
        $p->execute([$pharmacyId]);
        $pharmacyName = (string)($p->fetchColumn() ?: 'Farmácia');

        $ins = $db->prepare('INSERT INTO users(pharmacy_id,email,password_hash,name,role) VALUES(?,?,?,?,?)');
        $ins->execute([$pharmacyId,$email,password_hash($password,PASSWORD_DEFAULT),$pharmacyName . ' Admin','pharmacy_admin']);

        if ($generated) {
            $file = private_state_dir() . '/pharmacy-admin.credentials';
            $body = 'PHARMACY_ADMIN_EMAIL=' . $email . "\n" .
                    'PHARMACY_ADMIN_PASSWORD=' . $password . "\n" .
                    'PHARMACY_ID=' . $pharmacyId . "\n" .
                    'CREATED_AT=' . date(DATE_ATOM) . "\n";
            if (@file_put_contents($file, $body, LOCK_EX) !== false) @chmod($file, 0600);
        }
        unset($password);
    }

    public static function login(PDO $db,string $email,string $password):bool{
        $st=$db->prepare('SELECT * FROM users WHERE email=? AND active=1 LIMIT 1');$st->execute([$email]);$u=$st->fetch(); if(!$u||!password_verify($password,$u['password_hash']))return false;
        session_regenerate_id(true);$_SESSION['admin_id']=(int)$u['id'];$_SESSION['admin_email']=$u['email'];$_SESSION['role']=$u['role'];$_SESSION['pharmacy_id']=$u['pharmacy_id']!==null?(int)$u['pharmacy_id']:null;$_SESSION['name']=$u['name']?:$u['email'];return true;
    }
    public static function check():bool{return !empty($_SESSION['admin_id']);}
    public static function userId():int{return (int)($_SESSION['admin_id']??0);} public static function role():string{return (string)($_SESSION['role']??'');} public static function pharmacyId():?int{return isset($_SESSION['pharmacy_id'])?(int)$_SESSION['pharmacy_id']:null;}
    public static function can(string ...$roles):bool{return self::check()&&(self::role()==='super_admin'||in_array(self::role(),$roles,true));}
    public static function require(string ...$roles):void{if(!self::check()||($roles&&!self::can(...$roles))){header('Location: '.url('admin.php'));exit;}}
    public static function logout():void{$_SESSION=[];session_destroy();}
}
