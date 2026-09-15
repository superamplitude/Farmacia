<?php
declare(strict_types=1);

final class Catalog
{
    public static function classify(string $category, string $class, string $presentation = ''): array
    {
        $text = mb_strtoupper($category . ' ' . $class . ' ' . $presentation, 'UTF-8');
        $controlled = preg_match('/CONTROL|PSICOTR|ENTORPEC|PORTARIA\s*344|LISTA\s*[ABCDEF][0-9]?/', $text) === 1;
        $mip = preg_match('/ISENTO|\bMIP\b|SEM PRESCRI/', $text) === 1;
        $rx = preg_match('/PRESCRI|RETEN[CÇ][AÃ]O|RECEITA/', $text) === 1;
        $retain = preg_match('/RETEN[CÇ][AÃ]O|RETER|RETIDA/', $text) === 1;
        if ($controlled) return [1, 1, 1, 1, 0];
        if ($mip) return [0, 0, 0, 1, 0];
        if ($rx) return [1, $retain ? 1 : 0, 0, 1, 0];
        return [0, 0, 0, 1, 1];
    }

    public static function search(PDO $db, string $q, int $limit = 60): array
    {
        $q = trim($q);
        $limit = max(1, min(200, $limit));
        if ($q === '') return $db->query('SELECT * FROM medications ORDER BY product_name LIMIT ' . $limit)->fetchAll();
        $like = '%' . $q . '%';
        $st = $db->prepare('SELECT * FROM medications WHERE product_name LIKE ? OR active_ingredient LIKE ? OR company LIKE ? OR registration LIKE ? OR regulatory_category LIKE ? OR therapeutic_class LIKE ? ORDER BY product_name LIMIT ' . $limit);
        $st->execute([$like, $like, $like, $like, $like, $like]);
        return $st->fetchAll();
    }

    public static function publicSearch(PDO $db, int $pharmacyId, string $q, int $limit = 60): array
    {
        $q = trim($q);
        $limit = max(1, min(200, $limit));
        $args = [$pharmacyId];
        $sql = 'SELECT m.*, pp.price store_price, COALESCE(pp.stock,0) store_stock, COALESCE(pp.active,0) store_active, pp.image_url store_image_url
                FROM medications m
                LEFT JOIN pharmacy_products pp ON pp.medication_id=m.id AND pp.pharmacy_id=?';

        if ($q !== '') {
            $like = '%' . $q . '%';
            $sql .= ' WHERE (m.product_name LIKE ? OR m.active_ingredient LIKE ? OR m.company LIKE ? OR m.registration LIKE ? OR m.regulatory_category LIKE ? OR m.therapeutic_class LIKE ?)';
            array_push($args, $like, $like, $like, $like, $like, $like);
        }

        $sql .= ' ORDER BY COALESCE(pp.active,0) DESC, CASE WHEN m.image_url IS NOT NULL AND m.image_url<>\'\' THEN 0 ELSE 1 END, m.product_name LIMIT ' . $limit;
        $st = $db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    public static function categories(PDO $db, int $limit = 24): array
    {
        $limit = max(1, min(60, $limit));
        $sql = "SELECT category, COUNT(*) total FROM (
                    SELECT CASE
                        WHEN TRIM(COALESCE(regulatory_category,'')) <> '' THEN TRIM(regulatory_category)
                        WHEN TRIM(COALESCE(therapeutic_class,'')) <> '' THEN TRIM(therapeutic_class)
                        ELSE 'Outros'
                    END category
                    FROM medications
                ) x
                GROUP BY category
                ORDER BY total DESC, category ASC
                LIMIT " . $limit;
        return $db->query($sql)->fetchAll();
    }

    public static function total(PDO $db): int
    {
        return (int)$db->query('SELECT COUNT(*) FROM medications')->fetchColumn();
    }

    public static function storeProduct(PDO $db, int $pharmacyId, int $medicationId): ?array
    {
        $st = $db->prepare('SELECT m.*,pp.price store_price,pp.stock store_stock,pp.active store_active,pp.image_url store_image_url FROM pharmacy_products pp JOIN medications m ON m.id=pp.medication_id WHERE pp.pharmacy_id=? AND pp.medication_id=? LIMIT 1');
        $st->execute([$pharmacyId, $medicationId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function audit(PDO $db, string $action, string $entity = '', string $entityId = '', array $payload = []): void
    {
        $st = $db->prepare('INSERT INTO audit_logs(pharmacy_id,actor,action,entity,entity_id,payload,ip) VALUES(?,?,?,?,?,?,?)');
        $st->execute([
            Auth::pharmacyId(),
            $_SESSION['admin_email'] ?? 'system',
            $action,
            $entity,
            $entityId,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    }
}
