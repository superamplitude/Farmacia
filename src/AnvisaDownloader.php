<?php
declare(strict_types=1);

final class AnvisaDownloader
{
    private const SOURCE_HOST = 'dados.anvisa.gov.br';
    private const USER_AGENT = 'Farmacia-SuperAmplitude/1.0';
    private const CA_HOST_SUFFIXES = ['sectigo.com', 'comodoca.com', 'usertrust.com'];

    public static function download(string $url, string $target): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            throw new RuntimeException('URL Anvisa deve ser HTTPS válida');
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host !== self::SOURCE_HOST) {
            throw new RuntimeException('Host Anvisa não autorizado');
        }

        $ca = self::systemCa();
        [$ok, $http, $err] = self::curlDownload($url, $target, $ca);
        if (self::downloadOkay($ok, $http, $target)) return;

        @unlink($target);
        $primary = 'HTTP ' . $http . ($err !== '' ? ': ' . $err : '');
        if (!self::looksLikeCertificateFailure($err)) {
            throw new RuntimeException('Download Anvisa falhou ' . $primary);
        }

        $bundle = self::buildAiaBundle($host, $ca);
        try {
            [$ok2, $http2, $err2] = self::curlDownload($url, $target, $bundle);
            if (!self::downloadOkay($ok2, $http2, $target)) {
                @unlink($target);
                throw new RuntimeException('Download Anvisa com cadeia AIA falhou HTTP ' . $http2 . ($err2 !== '' ? ': ' . $err2 : ''));
            }
            echo "ANVISA_DOWNLOAD_TLS_VERIFIED=ok\n";
        } finally {
            @unlink($bundle);
        }
    }

    private static function systemCa(): string
    {
        foreach (['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/cert.pem'] as $candidate) {
            if (is_file($candidate) && filesize($candidate) > 0) return $candidate;
        }
        throw new RuntimeException('Bundle CA do sistema ausente');
    }

    private static function looksLikeCertificateFailure(string $error): bool
    {
        $e = strtolower($error);
        return str_contains($e, 'issuer certificate')
            || str_contains($e, 'certificate verify')
            || str_contains($e, 'ssl certificate');
    }

    private static function downloadOkay(bool $ok, int $http, string $target): bool
    {
        return $ok && $http >= 200 && $http < 300 && is_file($target) && filesize($target) >= 1024;
    }

    /** @return array{0:bool,1:int,2:string} */
    private static function curlDownload(string $url, string $target, string $ca): array
    {
        $out = fopen($target, 'wb');
        if (!$out) throw new RuntimeException('Falha ao criar arquivo temporário Anvisa');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $out,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_FAILONERROR => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 240,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO => $ca,
        ]);
        $ok = curl_exec($ch) === true;
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($out);
        return [$ok, $http, $err];
    }

    private static function buildAiaBundle(string $host, string $ca): string
    {
        $leafPem = self::captureLeafCertificate($host);
        $chain = [];
        $seen = [];
        $current = $leafPem;

        for ($depth = 0; $depth < 4; $depth++) {
            $issuerUrl = self::issuerAiaUrl($current);
            if ($issuerUrl === '') break;
            [$raw, $contentType] = self::fetchIssuerObject($issuerUrl, $ca);
            $certs = self::normalizeCertificateObject($raw);
            if (!$certs) {
                throw new RuntimeException('Objeto AIA não contém certificado X.509 válido; content-type=' . ($contentType ?: 'unknown'));
            }

            $added = 0;
            foreach ($certs as $pem) {
                $cert = @openssl_x509_read($pem);
                if ($cert === false) continue;
                $fp = function_exists('openssl_x509_fingerprint') ? (string)openssl_x509_fingerprint($cert, 'sha256') : hash('sha256', $pem);
                if ($fp === '' || isset($seen[$fp])) continue;
                $seen[$fp] = true;
                $chain[] = $pem;
                $added++;
            }
            if ($added === 0) break;
            $current = $certs[0];
        }

        if (!$chain) throw new RuntimeException('CA intermediária Anvisa não pôde ser descoberta por AIA');
        $bundle = tempnam(sys_get_temp_dir(), 'anvisa_ca_');
        if ($bundle === false) throw new RuntimeException('Falha ao criar bundle CA temporário');
        $base = file_get_contents($ca);
        if (!is_string($base) || $base === '') {
            @unlink($bundle);
            throw new RuntimeException('Bundle CA do sistema inválido');
        }
        if (file_put_contents($bundle, rtrim($base) . "\n" . implode("\n", $chain)) === false) {
            @unlink($bundle);
            throw new RuntimeException('Falha ao gravar bundle CA Anvisa');
        }
        @chmod($bundle, 0600);
        echo 'ANVISA_TLS_CHAIN_RECOVERY=AIA_INTERMEDIATE certificates=' . count($chain) . "\n";
        return $bundle;
    }

    private static function captureLeafCertificate(string $host): string
    {
        $ctx = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'capture_peer_cert_chain' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client('ssl://' . $host . ':443', $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!is_resource($socket)) throw new RuntimeException('Falha ao capturar certificado Anvisa: ' . $errstr);
        fclose($socket);
        $opts = stream_context_get_options($ctx);
        $leaf = $opts['ssl']['peer_certificate'] ?? null;
        if ($leaf === null) throw new RuntimeException('Servidor Anvisa não forneceu certificado TLS');
        $pem = '';
        if (!@openssl_x509_export($leaf, $pem) || $pem === '') throw new RuntimeException('Falha ao exportar certificado TLS Anvisa');
        return $pem;
    }

    private static function issuerAiaUrl(string $pem): string
    {
        $cert = @openssl_x509_read($pem);
        if ($cert === false) return '';
        $parsed = @openssl_x509_parse($cert);
        if (!is_array($parsed)) return '';
        $aia = (string)($parsed['extensions']['authorityInfoAccess'] ?? '');
        if (!preg_match('/CA Issuers\s*-\s*URI:([^\s,]+)/i', $aia, $m)) return '';
        $url = trim($m[1]);
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) return '';
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) return '';
        foreach (self::CA_HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) return $url;
        }
        throw new RuntimeException('Host AIA não autorizado: ' . $host);
    }

    /** @return array{0:string,1:string} */
    private static function fetchIssuerObject(string $url, string $ca): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_FAILONERROR => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => self::USER_AGENT . ' certificate-chain-repair',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO => $ca,
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '');
        $err = curl_error($ch);
        curl_close($ch);
        if (!is_string($body) || $body === '' || $http < 200 || $http >= 300) {
            throw new RuntimeException('Falha ao obter CA intermediária HTTP ' . $http . ($err !== '' ? ': ' . $err : ''));
        }
        return [$body, $contentType];
    }

    /** @return list<string> */
    private static function normalizeCertificateObject(string $raw): array
    {
        $certs = self::extractPemCertificates($raw);
        if ($certs) return $certs;

        foreach ([
            ['/usr/bin/openssl', 'x509', '-inform', 'DER', '-outform', 'PEM'],
            ['/usr/bin/openssl', 'pkcs7', '-inform', 'DER', '-print_certs', '-outform', 'PEM'],
            ['/usr/bin/openssl', 'pkcs7', '-inform', 'PEM', '-print_certs', '-outform', 'PEM'],
        ] as $command) {
            [$code, $stdout] = self::runProcess($command, $raw);
            if ($code !== 0) continue;
            $certs = self::extractPemCertificates($stdout);
            if ($certs) return $certs;
        }
        return [];
    }

    /** @return list<string> */
    private static function extractPemCertificates(string $text): array
    {
        if (!preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $text, $m)) return [];
        return array_values(array_map(static fn(string $pem): string => trim($pem) . "\n", $m[0]));
    }

    /** @param list<string> $command @return array{0:int,1:string,2:string} */
    private static function runProcess(array $command, string $stdin): array
    {
        if (!function_exists('proc_open') || !is_executable('/usr/bin/openssl')) return [127, '', 'openssl unavailable'];
        $pipes = [];
        $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) return [126, '', 'proc_open failed'];
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code = proc_close($proc);
        return [$code, $stdout, $stderr];
    }
}
