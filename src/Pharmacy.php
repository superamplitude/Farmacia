<?php
declare(strict_types=1);

final class Pharmacy
{
    public static function ensureDefault(PDO $db):array{
        $slug=(string)env('PUBLIC_PHARMACY_SLUG','matriz');$st=$db->prepare('SELECT * FROM pharmacies WHERE slug=? LIMIT 1');$st->execute([$slug]);$p=$st->fetch();
        if(!$p){$ins=$db->prepare('INSERT INTO pharmacies(name,slug,status,email,delivery_enabled) VALUES(?,?,?,?,1)');$ins->execute([env('APP_NAME','Farmácia SuperAmplitude'),$slug,'active',env('SUPERADMIN_EMAIL','')]);$st->execute([$slug]);$p=$st->fetch();}
        return $p;
    }
    public static function current(PDO $db):array{
        $slug=trim((string)($_GET['loja']??env('PUBLIC_PHARMACY_SLUG','matriz')));$st=$db->prepare("SELECT * FROM pharmacies WHERE slug=? AND status='active' LIMIT 1");$st->execute([$slug]);return $st->fetch()?:self::ensureDefault($db);
    }
    public static function deliveryQuote(PDO $db,int $pharmacyId,string $zip,string $city,float $subtotal):array{
        $zip=preg_replace('/\D/','',$zip);$st=$db->prepare('SELECT * FROM delivery_zones WHERE pharmacy_id=? AND active=1 ORDER BY LENGTH(zip_prefix) DESC');$st->execute([$pharmacyId]);
        foreach($st->fetchAll() as $z){$match=($z['zip_prefix']&&str_starts_with($zip,preg_replace('/\D/','',$z['zip_prefix'])))||($z['city']&&mb_strtolower(trim($city))===mb_strtolower(trim($z['city'])));if($match){$fee=(float)$z['fee'];if($z['free_above']!==null&&$subtotal>=(float)$z['free_above'])$fee=0;return ['fee'=>$fee,'eta_min'=>$z['eta_min'],'eta_max'=>$z['eta_max'],'zone'=>$z['name']];}}
        $fee=(float)env('DELIVERY_DEFAULT_FEE','12');if($subtotal>=(float)env('DELIVERY_FREE_ABOVE','150'))$fee=0;return ['fee'=>$fee,'eta_min'=>null,'eta_max'=>null,'zone'=>'Padrão'];
    }
}
