<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$rows = Catalog::categories($db, 30);
echo json_encode([
    'total' => Catalog::total($db),
    'categories' => array_map(static fn(array $row): array => [
        'name' => (string)$row['category'],
        'total' => (int)$row['total'],
    ], $rows),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
