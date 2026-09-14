<?php
declare(strict_types=1);

final class Catalog
{
    public static function classify(string $category, string $class, string $presentation=''): array
    {
        $text = mb_strtoupper($category . ' ' . $class . ' ' . $presentation, 'UTF-8');
        $controlled = preg_match('/CONTROL|PSICOTR|ENTORPEC|PORTARIA\s*344|LISTA\s*[ABCDEF][0-9]?/', $text) === 1;
        $mip = preg_match('/ISENTO|\bMIP\b|SEM PRESCRI/', $text) === 1;
        $rx = preg_match('/PRESCRI|RETEN[CÇ][AÃ]O|RECEITA/', $text) === 1;
        $retain = preg_match('/RETEN[CÇ][AÃ]O|RETER|RETIDA/', $text) === 1;
        if ($controlled) return [1, $retain ? 1 : 1, 1, 0, 0];
        if ($mip) return [0, 0, 0, 1, 0];
        if ($rx) return [1, $retain ? 1 : 0, 0, 1, 0];
        return [0, 0, 0, 0, 1];
    }

    public static function search(PDO $db, string $q, int $limit=60): array
    {
        $q = trim($q);
        if ($q === '') return $db->query('SELECT * FROM medications ORDER BY product_name LIMIT ' . (int)$limit)->fetchAll();
        $like = '%' . $q . '%';
        $st = $db->prepare('SELECT * FROM medications WHERE product_name LIKE ? OR active_ingredient LIKE ? OR company LIKE ? OR registration LIKE ? ORDER BY product_name LIMIT ' . (int)$limit);
        $st->execute([$like,$like,$like,$like]);
        return $st->fetchAll();
    }

    public static function audit(PDO $db, string $action, string $entity='', string $entityId='', array $payload=[]): void
    {
        $st = $db->prepare('INSERT INTO audit_logs(actor,action,entity,entity_id,payload) VALUES(?,?,?,?,?)');
        $st->execute([$_SESSION['admin_email'] ?? 'system',$action,$entity,$entityId,json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }
}
