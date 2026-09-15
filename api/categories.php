<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$payload = [
    'total' => Catalog::total($db),
    'categories' => Catalog::consumerCategoryCounts($db),
];

$json = json_encode(
    $payload,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
);

if ($json === false) {
    http_response_code(500);
    echo '{"error":"Falha ao serializar categorias"}';
    exit;
}

echo $json;
