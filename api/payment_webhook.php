<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (strtolower((string)($_GET['provider'] ?? 'mercadopago')) !== 'mercadopago') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'provider_not_supported']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) $body = [];
$paymentId = (string)($body['data']['id'] ?? $_GET['data_id'] ?? $_GET['id'] ?? '');
$type = (string)($body['type'] ?? $_GET['type'] ?? 'payment');

if ($paymentId === '' || ($type !== '' && $type !== 'payment')) {
    echo json_encode(['ok' => true, 'ignored' => true]);
    exit;
}

try {
    $order = PaymentGateway::syncMercadoPago($db, $paymentId);
    echo json_encode(['ok' => true, 'order_id' => $order['id'] ?? null, 'payment_status' => $order['payment_status'] ?? null]);
} catch (Throwable $e) {
    error_log('payment_webhook: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'payment_sync_failed']);
}
