<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
if (PHP_SAPI !== 'cli') exit("CLI only\n");

$pharmacies = $db->query("SELECT id,name FROM pharmacies WHERE status='active' ORDER BY id")->fetchAll();
$totalMeds = (int)$db->query('SELECT COUNT(*) FROM medications')->fetchColumn();

if ($totalMeds < 1) {
    fwrite(STDERR, "STORE_CATALOG_FAIL medications=0\n");
    exit(2);
}

$db->beginTransaction();
try {
    $insert = $db->prepare("INSERT OR IGNORE INTO pharmacy_products(pharmacy_id,medication_id,sku,price,stock,active,image_url)
        SELECT ?, m.id,
               CASE WHEN TRIM(COALESCE(m.registration,''))<>'' THEN 'ANVISA-' || m.registration ELSE 'MED-' || m.id END,
               NULL, 0, 0, NULL
        FROM medications m");

    $rows = [];
    foreach ($pharmacies as $pharmacy) {
        $pid = (int)$pharmacy['id'];
        $beforeSt = $db->prepare('SELECT COUNT(*) FROM pharmacy_products WHERE pharmacy_id=?');
        $beforeSt->execute([$pid]);
        $before = (int)$beforeSt->fetchColumn();

        $insert->execute([$pid]);

        $afterSt = $db->prepare('SELECT COUNT(*) FROM pharmacy_products WHERE pharmacy_id=?');
        $afterSt->execute([$pid]);
        $after = (int)$afterSt->fetchColumn();
        $rows[] = ['pharmacy_id'=>$pid,'name'=>$pharmacy['name'],'before'=>$before,'after'=>$after,'added'=>max(0,$after-$before)];
    }
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'STORE_CATALOG_FAIL ' . $e->getMessage() . "\n");
    exit(3);
}

foreach ($rows as $row) {
    echo 'STORE_CATALOG pharmacy_id=' . $row['pharmacy_id'] .
         ' before=' . $row['before'] .
         ' after=' . $row['after'] .
         ' added=' . $row['added'] . "\n";
}

echo 'STORE_CATALOG_OK medications=' . $totalMeds . ' pharmacies=' . count($pharmacies) . "\n";
