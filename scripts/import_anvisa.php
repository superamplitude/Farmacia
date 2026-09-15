<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
if (PHP_SAPI !== 'cli') exit("CLI only\n");

$url = trim((string)env('ANVISA_CSV_URL', ''));
if ($url === '') {
    fwrite(STDERR, "ANVISA_FAIL URL não configurada\n");
    exit(2);
}
$parts = parse_url($url);
if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
    fwrite(STDERR, "ANVISA_FAIL URL deve ser HTTPS válida\n");
    exit(2);
}
$sourceHost = strtolower((string)$parts['host']);
if ($sourceHost !== 'dados.anvisa.gov.br') {
    fwrite(STDERR, "ANVISA_FAIL host de origem não autorizado\n");
    exit(2);
}

$maxAgeHours = max(0, (int)env('ANVISA_IMPORT_MAX_AGE_HOURS', '24'));
if ($maxAgeHours > 0) {
    $last = $db->query("SELECT finished_at,rows_written FROM import_runs WHERE source='ANVISA' AND status='done' ORDER BY id DESC LIMIT 1")->fetch();
    $count = (int)$db->query('SELECT COUNT(*) FROM medications')->fetchColumn();
    if ($last && $count > 0 && !empty($last['finished_at'])) {
        $age = time() - (strtotime((string)$last['finished_at']) ?: 0);
        if ($age >= 0 && $age < $maxAgeHours * 3600) {
            echo "ANVISA_SKIP recent_success age_seconds={$age} medications={$count}\n";
            exit(0);
        }
    }
}

$tmp = sys_get_temp_dir() . '/anvisa_medicamentos_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.csv';
$rid = 0;
$seen = 0;
$written = 0;
$fh = null;

$systemCa = static function(): string {
    foreach (['/etc/ssl/certs/ca-certificates.crt','/etc/pki/tls/certs/ca-bundle.crt','/etc/ssl/cert.pem'] as $candidate) {
        if (is_file($candidate) && filesize($candidate) > 0) return $candidate;
    }
    return '';
};

$normalizeCertificate = static function(string $raw): string {
    if (str_contains($raw, '-----BEGIN CERTIFICATE-----')) {
        if (preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $raw, $m)) return $m[0] . "\n";
    }
    if (!function_exists('proc_open') || !is_executable('/usr/bin/openssl')) {
        throw new RuntimeException('OpenSSL indisponível para converter certificado intermediário');
    }
    $pipes = [];
    $proc = proc_open(['/usr/bin/openssl','x509','-inform','DER','-outform','PEM'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($proc)) throw new RuntimeException('Falha ao iniciar OpenSSL para certificado intermediário');
    fwrite($pipes[0], $raw); fclose($pipes[0]);
    $pem = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0 || !str_contains((string)$pem, '-----BEGIN CERTIFICATE-----')) {
        throw new RuntimeException('Certificado intermediário inválido: ' . trim((string)$err));
    }
    return (string)$pem;
};

$issuerAiaUrl = static function(string $pem): string {
    $cert = @openssl_x509_read($pem);
    if ($cert === false) return '';
    $parsed = @openssl_x509_parse($cert);
    if (!is_array($parsed)) return '';
    $aia = (string)($parsed['extensions']['authorityInfoAccess'] ?? '');
    if (!preg_match('/CA Issuers\s*-\s*URI:([^\s,]+)/i', $aia, $m)) return '';
    $candidate = trim($m[1]);
    $p = parse_url($candidate);
    if (!is_array($p) || !in_array(strtolower((string)($p['scheme'] ?? '')), ['http','https'], true)) return '';
    $host = strtolower((string)($p['host'] ?? ''));
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) return '';
    $allowed = ['sectigo.com','comodoca.com','usertrust.com'];
    $ok = false;
    foreach ($allowed as $suffix) {
        if ($host === $suffix || str_ends_with($host, '.' . $suffix)) { $ok = true; break; }
    }
    return $ok ? $candidate : '';
};

$fetchCertificateObject = static function(string $url, string $ca): string {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_FAILONERROR => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'Farmacia-SuperAmplitude/1.0 certificate-chain-repair',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($ca !== '') $opts[CURLOPT_CAINFO] = $ca;
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!is_string($body) || $body === '' || $http < 200 || $http >= 300) {
        throw new RuntimeException('Falha ao obter CA intermediária HTTP ' . $http . ($err !== '' ? ': ' . $err : ''));
    }
    return $body;
};

$buildAiaBundle = static function(string $host, string $ca) use ($issuerAiaUrl, $fetchCertificateObject, $normalizeCertificate): string {
    if ($host !== 'dados.anvisa.gov.br' || $ca === '') return '';

    $ctx = stream_context_create(['ssl' => [
        'capture_peer_cert' => true,
        'capture_peer_cert_chain' => true,
        'verify_peer' => false,
        'verify_peer_name' => false,
        'SNI_enabled' => true,
        'peer_name' => $host,
    ]]);
    $errno = 0; $errstr = '';
    $sock = @stream_socket_client('ssl://' . $host . ':443', $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!is_resource($sock)) throw new RuntimeException('Falha ao capturar certificado Anvisa: ' . $errstr);
    fclose($sock);
    $opts = stream_context_get_options($ctx);
    $leaf = $opts['ssl']['peer_certificate'] ?? null;
    if ($leaf === null) throw new RuntimeException('Servidor Anvisa não forneceu certificado TLS');
    $leafPem = '';
    if (!@openssl_x509_export($leaf, $leafPem) || $leafPem === '') throw new RuntimeException('Falha ao exportar certificado TLS da Anvisa');

    $chain = [];
    $seen = [];
    $current = $leafPem;
    for ($depth = 0; $depth < 4; $depth++) {
        $issuerUrl = $issuerAiaUrl($current);
        if ($issuerUrl === '') break;
        $raw = $fetchCertificateObject($issuerUrl, $ca);
        $pem = $normalizeCertificate($raw);
        $cert = @openssl_x509_read($pem);
        $fingerprint = $cert !== false && function_exists('openssl_x509_fingerprint') ? (string)openssl_x509_fingerprint($cert, 'sha256') : hash('sha256', $pem);
        if ($fingerprint === '' || isset($seen[$fingerprint])) break;
        $seen[$fingerprint] = true;
        $chain[] = $pem;
        $current = $pem;
    }
    if (!$chain) throw new RuntimeException('CA intermediária da Anvisa não pôde ser descoberta por AIA');

    $bundle = tempnam(sys_get_temp_dir(), 'anvisa_ca_');
    if ($bundle === false) throw new RuntimeException('Falha ao criar bundle CA temporário');
    $base = file_get_contents($ca);
    if (!is_string($base) || $base === '') { @unlink($bundle); throw new RuntimeException('Bundle CA do sistema inválido'); }
    file_put_contents($bundle, rtrim($base) . "\n" . implode("\n", $chain));
    chmod($bundle, 0600);
    echo 'ANVISA_TLS_CHAIN_RECOVERY=AIA_INTERMEDIATE depth=' . count($chain) . "\n";
    return $bundle;
};

$curlDownload = static function(string $url, string $tmp, string $ca): array {
    $out = fopen($tmp, 'wb');
    if (!$out) throw new RuntimeException('Falha ao criar arquivo temporário');
    $ch = curl_init($url);
    $opts = [
        CURLOPT_FILE => $out,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_FAILONERROR => true,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 240,
        CURLOPT_USERAGENT => 'Farmacia-SuperAmplitude/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($ca !== '') $opts[CURLOPT_CAINFO] = $ca;
    curl_setopt_array($ch, $opts);
    $ok = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($out);
    return [$ok === true, $http, $err];
};

$downloadSecure = static function(string $url, string $tmp, string $host) use ($systemCa, $buildAiaBundle, $curlDownload): void {
    $ca = $systemCa();
    [$ok, $http, $err] = $curlDownload($url, $tmp, $ca);
    if ($ok && $http >= 200 && $http < 300 && is_file($tmp) && filesize($tmp) >= 1024) return;

    @unlink($tmp);
    $primaryError = 'HTTP ' . $http . ($err !== '' ? ': ' . $err : '');
    $needsChainRepair = str_contains(strtolower($err), 'issuer certificate') || str_contains(strtolower($err), 'certificate verify') || str_contains(strtolower($err), 'ssl certificate');
    if (!$needsChainRepair) throw new RuntimeException('Download Anvisa falhou ' . $primaryError);

    $bundle = '';
    try {
        $bundle = $buildAiaBundle($host, $ca);
        if ($bundle === '') throw new RuntimeException('bundle AIA não gerado');
        [$ok2, $http2, $err2] = $curlDownload($url, $tmp, $bundle);
        if (!$ok2 || $http2 < 200 || $http2 >= 300 || !is_file($tmp) || filesize($tmp) < 1024) {
            @unlink($tmp);
            throw new RuntimeException('Download com cadeia AIA falhou HTTP ' . $http2 . ($err2 !== '' ? ': ' . $err2 : ''));
        }
        echo "ANVISA_DOWNLOAD_TLS_VERIFIED=ok\n";
    } finally {
        if ($bundle !== '') @unlink($bundle);
    }
};

try {
    $run = $db->prepare("INSERT INTO import_runs(source,status,message) VALUES('ANVISA','running','download')");
    $run->execute();
    $rid = (int)$db->lastInsertId();

    $downloadSecure($url, $tmp, $sourceHost);
    if (!is_file($tmp) || filesize($tmp) < 1024) throw new RuntimeException('CSV Anvisa vazio ou incompleto');

    $fh = fopen($tmp, 'rb');
    if (!$fh) throw new RuntimeException('Falha ao abrir CSV baixado');
    $header = fgetcsv($fh, 0, ';');
    if (!$header) throw new RuntimeException('CSV sem cabeçalho');

    $upper = static function(string $s): string {
        return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
    };
    $norm = static function($s) use ($upper): string {
        $v = $upper(trim((string)$s));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v);
        if ($ascii !== false) $v = $ascii;
        return trim((string)preg_replace('/[^A-Z0-9]+/', '_', $v), '_');
    };
    $keys = array_map($norm, $header);
    $pick = static function(array $row, array $names) use ($keys, $norm): string {
        foreach ($names as $n) {
            $i = array_search($norm($n), $keys, true);
            if ($i !== false && isset($row[$i])) return trim((string)$row[$i]);
        }
        return '';
    };

    $requiredHeaders = ['NUMERO_REGISTRO_PRODUTO','NOME_PRODUTO'];
    foreach ($requiredHeaders as $requiredHeader) {
        if (!in_array($norm($requiredHeader), $keys, true)) throw new RuntimeException('CSV Anvisa incompatível: coluna ausente ' . $requiredHeader);
    }

    $up = $db->prepare("INSERT INTO medications(registration,product_name,active_ingredient,company,regulatory_category,therapeutic_class,presentation,registration_status,requires_prescription,retain_prescription,controlled,remote_delivery_allowed,review_required,bula_url,source,source_updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'ANVISA',CURRENT_TIMESTAMP) ON CONFLICT(registration) DO UPDATE SET product_name=excluded.product_name,active_ingredient=excluded.active_ingredient,company=excluded.company,regulatory_category=excluded.regulatory_category,therapeutic_class=excluded.therapeutic_class,presentation=excluded.presentation,registration_status=excluded.registration_status,requires_prescription=excluded.requires_prescription,retain_prescription=excluded.retain_prescription,controlled=excluded.controlled,remote_delivery_allowed=excluded.remote_delivery_allowed,review_required=excluded.review_required,bula_url=excluded.bula_url,source_updated_at=CURRENT_TIMESTAMP");

    $db->beginTransaction();
    while (($row = fgetcsv($fh, 0, ';')) !== false) {
        $seen++;
        $reg = $pick($row, ['NUMERO_REGISTRO_PRODUTO','REGISTRO','NUMERO_REGISTRO']);
        $name = $pick($row, ['NOME_PRODUTO','PRODUTO','NOME_COMERCIAL']);
        if ($reg === '' || $name === '') continue;

        $active = $pick($row, ['PRINCIPIO_ATIVO','PRINCIPIO ATIVO']);
        $company = $pick($row, ['EMPRESA_DETENTORA_REGISTRO','EMPRESA','RAZAO_SOCIAL']);
        $cat = $pick($row, ['CATEGORIA_REGULATORIA','CATEGORIA']);
        $class = $pick($row, ['CLASSE_TERAPEUTICA','CLASSE']);
        $pres = $pick($row, ['APRESENTACAO','APRESENTACAO_PRODUTO']);
        $status = $pick($row, ['SITUACAO_REGISTRO','SITUACAO']);
        [$rx,$retain,$controlled,$remote,$review] = Catalog::classify($cat, $class, $pres);

        $up->execute([$reg,$name,$active,$company,$cat,$class,$pres,$status,$rx,$retain,$controlled,$remote,$review,env('BULARIO_URL','https://consultas.anvisa.gov.br/#/bulario/')]);
        $written++;
        if ($written % 1000 === 0) {
            $db->commit();
            echo "ANVISA_PROGRESS written={$written}\n";
            $db->beginTransaction();
        }
    }
    if ($db->inTransaction()) $db->commit();
    fclose($fh);
    $fh = null;

    if ($written === 0) throw new RuntimeException('Nenhum medicamento válido foi importado');

    $done = $db->prepare("UPDATE import_runs SET status='done',rows_seen=?,rows_written=?,message='ok',finished_at=CURRENT_TIMESTAMP WHERE id=?");
    $done->execute([$seen, $written, $rid]);
    @unlink($tmp);
    echo "ANVISA_OK seen={$seen} written={$written}\n";
    exit(0);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    if (is_resource($fh)) fclose($fh);
    @unlink($tmp);
    if ($rid > 0) {
        try {
            $failed = $db->prepare("UPDATE import_runs SET status='failed',rows_seen=?,rows_written=?,message=?,finished_at=CURRENT_TIMESTAMP WHERE id=?");
            $failed->execute([$seen, $written, substr($e->getMessage(), 0, 500), $rid]);
        } catch (Throwable) {}
    }
    fwrite(STDERR, 'ANVISA_FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
