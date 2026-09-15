<?php
declare(strict_types=1);

final class PaymentGateway
{
    public static function provider(): string
    {
        return strtolower(trim((string)env('PAYMENT_PROVIDER', 'delivery')));
    }

    public static function mercadoPagoReady(): bool
    {
        return self::provider() === 'mercadopago' && trim((string)env('MERCADOPAGO_ACCESS_TOKEN', '')) !== '';
    }

    public static function availableMethods(): array
    {
        $methods = [
            'card_delivery' => 'Cartão na entrega',
            'cash' => 'Dinheiro',
        ];
        if (self::mercadoPagoReady()) {
            $methods = ['pix' => 'PIX'] + $methods;
        }
        return $methods;
    }

    public static function methodLabel(string $method): string
    {
        return self::availableMethods()[$method] ?? match ($method) {
            'pix' => 'PIX',
            'card_delivery' => 'Cartão na entrega',
            'cash' => 'Dinheiro',
            default => $method,
        };
    }

    public static function createPix(PDO $db, int $orderId): array
    {
        if (!self::mercadoPagoReady()) {
            throw new RuntimeException('PIX online indisponível: gateway não configurado.');
        }

        $st = $db->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');
        $st->execute([$orderId]);
        $order = $st->fetch();
        if (!$order) throw new RuntimeException('Pedido não encontrado.');
        if (($order['payment_method'] ?? '') !== 'pix') throw new RuntimeException('Pedido não utiliza PIX.');
        if (($order['payment_status'] ?? '') === 'paid') return self::publicPaymentData($order);

        if (!empty($order['payment_external_id'])) {
            self::syncMercadoPago($db, (string)$order['payment_external_id']);
            $st->execute([$orderId]);
            return self::publicPaymentData($st->fetch() ?: $order);
        }

        $appUrl = rtrim((string)env('APP_URL', 'https://farmacia.superamplitude.com'), '/');
        $appKey = (string)env('APP_KEY', 'farmacia');
        $idempotency = hash_hmac('sha256', 'mercadopago-pix-order-' . $orderId, $appKey);
        $name = trim((string)$order['customer_name']);
        $parts = preg_split('/\s+/u', $name, 2) ?: [];

        $payload = [
            'transaction_amount' => round((float)$order['total'], 2),
            'description' => 'Pedido Farmácia #' . $orderId,
            'payment_method_id' => 'pix',
            'external_reference' => 'farmacia-order-' . $orderId,
            'notification_url' => $appUrl . '/api/payment_webhook.php?provider=mercadopago',
            'payer' => [
                'email' => (string)$order['customer_email'],
                'first_name' => (string)($parts[0] ?? 'Cliente'),
                'last_name' => (string)($parts[1] ?? ''),
            ],
        ];

        $data = self::request('POST', '/v1/payments', $payload, ['X-Idempotency-Key: ' . $idempotency]);
        $externalId = (string)($data['id'] ?? '');
        if ($externalId === '') throw new RuntimeException('Gateway não retornou identificador do pagamento.');

        $tx = $data['point_of_interaction']['transaction_data'] ?? [];
        $status = self::mapStatus((string)($data['status'] ?? 'pending'));
        $up = $db->prepare('UPDATE orders SET payment_provider=?,payment_external_id=?,payment_status=?,payment_qr_code=?,payment_qr_code_base64=?,payment_ticket_url=?,payment_expires_at=?,payment_updated_at=CURRENT_TIMESTAMP,paid_at=CASE WHEN ?="paid" THEN CURRENT_TIMESTAMP ELSE paid_at END,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $up->execute([
            'mercadopago',
            $externalId,
            $status,
            (string)($tx['qr_code'] ?? ''),
            (string)($tx['qr_code_base64'] ?? ''),
            (string)($tx['ticket_url'] ?? ''),
            self::normalizeDate($data['date_of_expiration'] ?? null),
            $status,
            $orderId,
        ]);
        self::event($db, 'mercadopago', $externalId . ':created', $orderId, $data);

        $st->execute([$orderId]);
        return self::publicPaymentData($st->fetch() ?: $order);
    }

    public static function syncMercadoPago(PDO $db, string $paymentId): ?array
    {
        if (!self::mercadoPagoReady() || $paymentId === '') return null;
        $data = self::request('GET', '/v1/payments/' . rawurlencode($paymentId));
        $external = (string)($data['external_reference'] ?? '');
        if (!preg_match('/^farmacia-order-(\d+)$/', $external, $m)) return null;
        $orderId = (int)$m[1];

        $st = $db->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');
        $st->execute([$orderId]);
        $order = $st->fetch();
        if (!$order) return null;
        if (!empty($order['payment_external_id']) && (string)$order['payment_external_id'] !== $paymentId) {
            throw new RuntimeException('Pagamento não corresponde ao pedido informado.');
        }

        $status = self::mapStatus((string)($data['status'] ?? 'pending'));
        $tx = $data['point_of_interaction']['transaction_data'] ?? [];
        $up = $db->prepare('UPDATE orders SET payment_provider=?,payment_external_id=?,payment_status=?,payment_qr_code=COALESCE(NULLIF(?,""),payment_qr_code),payment_qr_code_base64=COALESCE(NULLIF(?,""),payment_qr_code_base64),payment_ticket_url=COALESCE(NULLIF(?,""),payment_ticket_url),payment_expires_at=COALESCE(?,payment_expires_at),payment_updated_at=CURRENT_TIMESTAMP,paid_at=CASE WHEN ?="paid" AND paid_at IS NULL THEN CURRENT_TIMESTAMP ELSE paid_at END,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $up->execute([
            'mercadopago',
            $paymentId,
            $status,
            (string)($tx['qr_code'] ?? ''),
            (string)($tx['qr_code_base64'] ?? ''),
            (string)($tx['ticket_url'] ?? ''),
            self::normalizeDate($data['date_of_expiration'] ?? null),
            $status,
            $orderId,
        ]);
        self::event($db, 'mercadopago', $paymentId . ':' . ($data['status'] ?? 'unknown') . ':' . ($data['date_last_updated'] ?? ''), $orderId, $data);

        $st->execute([$orderId]);
        return $st->fetch() ?: null;
    }

    public static function publicPaymentData(array $order): array
    {
        return [
            'method' => (string)($order['payment_method'] ?? ''),
            'provider' => (string)($order['payment_provider'] ?? ''),
            'status' => (string)($order['payment_status'] ?? 'pending'),
            'qr_code' => (string)($order['payment_qr_code'] ?? ''),
            'qr_code_base64' => (string)($order['payment_qr_code_base64'] ?? ''),
            'ticket_url' => (string)($order['payment_ticket_url'] ?? ''),
            'expires_at' => $order['payment_expires_at'] ?? null,
        ];
    }

    private static function request(string $method, string $path, ?array $payload = null, array $extraHeaders = []): array
    {
        $token = trim((string)env('MERCADOPAGO_ACCESS_TOKEN', ''));
        if ($token === '') throw new RuntimeException('Credencial do Mercado Pago não configurada.');
        $base = rtrim((string)env('MERCADOPAGO_API_BASE', 'https://api.mercadopago.com'), '/');
        $headers = array_merge([
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Content-Type: application/json',
        ], $extraHeaders);

        $ch = curl_init($base . $path);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($payload !== null) $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $code < 200 || $code >= 300) {
            $detail = is_string($raw) ? mb_substr($raw, 0, 700) : $error;
            throw new RuntimeException('Falha no gateway de pagamento HTTP ' . $code . ($detail !== '' ? ': ' . $detail : ''));
        }
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) throw new RuntimeException('Resposta inválida do gateway de pagamento.');
        return $data;
    }

    private static function mapStatus(string $status): string
    {
        return match (strtolower($status)) {
            'approved' => 'paid',
            'refunded', 'charged_back' => 'refunded',
            'rejected', 'cancelled' => 'failed',
            default => 'pending',
        };
    }

    private static function normalizeDate(mixed $value): ?string
    {
        if (!$value) return null;
        try { return (new DateTimeImmutable((string)$value))->format('Y-m-d H:i:s'); }
        catch (Throwable) { return null; }
    }

    private static function event(PDO $db, string $provider, string $eventKey, int $orderId, array $payload): void
    {
        $st = $db->prepare('INSERT OR IGNORE INTO payment_events(provider,event_key,order_id,payload) VALUES(?,?,?,?)');
        $st->execute([$provider, $eventKey, $orderId, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }
}
