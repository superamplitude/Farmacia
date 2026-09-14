<?php
declare(strict_types=1);

final class R2Storage
{
    public static function config(): array
    {
        $account = trim((string)env('R2_ACCOUNT_ID', ''));
        $endpoint = trim((string)env('R2_ENDPOINT', ''));
        if ($endpoint === '' && $account !== '') {
            $endpoint = 'https://' . $account . '.r2.cloudflarestorage.com';
        }
        return [
            'account_id' => $account,
            'bucket' => trim((string)env('R2_BUCKET', 'superamplitude')),
            'endpoint' => rtrim($endpoint, '/'),
            'catalog_url' => trim((string)env('R2_CATALOG_URL', '')),
            'public_base_url' => rtrim((string)env('IMAGE_BASE_URL', 'https://imagem.superamplitude.com'), '/'),
            'access_key' => trim((string)env('R2_ACCESS_KEY_ID', '')),
            'secret_key' => trim((string)env('R2_SECRET_ACCESS_KEY', '')),
        ];
    }

    public static function readyForWrite(): bool
    {
        $c = self::config();
        return $c['endpoint'] !== '' && $c['bucket'] !== '' && $c['access_key'] !== '' && $c['secret_key'] !== '';
    }

    public static function publicUrl(string $key): string
    {
        $c = self::config();
        return $c['public_base_url'] . '/' . ltrim($key, '/');
    }

    public static function put(string $key, string $contents, string $contentType='application/octet-stream'): string
    {
        $c = self::config();
        if (!self::readyForWrite()) {
            throw new RuntimeException('Credenciais R2 de escrita não configuradas.');
        }
        $key = ltrim($key, '/');
        $host = parse_url($c['endpoint'], PHP_URL_HOST);
        if (!$host) throw new RuntimeException('Endpoint R2 inválido.');
        $amzDate = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        $payloadHash = hash('sha256', $contents);
        $canonicalUri = '/' . rawurlencode($c['bucket']) . '/' . str_replace('%2F', '/', rawurlencode($key));
        $canonicalHeaders = 'content-type:' . trim($contentType) . "\n" .
            'host:' . $host . "\n" .
            'x-amz-content-sha256:' . $payloadHash . "\n" .
            'x-amz-date:' . $amzDate . "\n";
        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';
        $canonicalRequest = "PUT\n" . $canonicalUri . "\n\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;
        $scope = $date . '/auto/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $c['secret_key'], true);
        $kRegion = hash_hmac('sha256', 'auto', $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        $authorization = 'AWS4-HMAC-SHA256 Credential=' . $c['access_key'] . '/' . $scope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
        $url = $c['endpoint'] . $canonicalUri;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $contents,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $authorization,
                'Content-Type: ' . $contentType,
                'Host: ' . $host,
                'x-amz-content-sha256: ' . $payloadHash,
                'x-amz-date: ' . $amzDate,
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false || $code < 200 || $code >= 300) {
            throw new RuntimeException('Falha R2 HTTP ' . $code . ($err ? ': ' . $err : ''));
        }
        return self::publicUrl($key);
    }
}
