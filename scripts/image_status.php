<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (PHP_SAPI !== 'cli') exit("CLI only\n");

$total = (int)$db->query('SELECT COUNT(*) FROM medications')->fetchColumn();
$withAny = (int)$db->query("SELECT COUNT(*) FROM medications WHERE TRIM(COALESCE(image_url,''))<>''")->fetchColumn();
$base = rtrim((string)env('IMAGE_BASE_URL', 'https://img.farmacia.superamplitude.com'), '/');
$st = $db->prepare("SELECT COUNT(*) FROM medications WHERE image_url LIKE ?");
$st->execute([$base . '/%']);
$withR2 = (int)$st->fetchColumn();
$missing = max(0, $total - $withAny);
$coverage = $total > 0 ? round(($withAny / $total) * 100, 2) : 0;

echo "IMAGE_STATUS total={$total} any={$withAny} r2={$withR2} missing={$missing} coverage={$coverage}% base={$base}\n";
