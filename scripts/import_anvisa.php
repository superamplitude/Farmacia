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

$downloadSecure = static function(string $url, string $tmp): void {
    $ca = '';
    foreach (['/etc/ssl/certs/ca-certificates.crt','/etc/pki/tls/certs/ca-bundle.crt','/etc/ssl/cert.pem'] as $candidate) {
        if (is_file($candidate) && filesize($candidate) > 0) { $ca = $candidate; break; }
    }

    $out = fopen($tmp, 'wb');
    if (!$out) throw new RuntimeException('Falha ao criar arquivo temporário');
    $ch = curl_init($url);
    $opts = [
        CURLOPT_FILE => $out,
        CURLOPT_FOLLOWLOCATION => true,
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

    if ($ok && $http >= 200 && $http < 300 && is_file($tmp) && filesize($tmp) >= 1024) return;

    @unlink($tmp);
    $phpError = 'HTTP ' . $http . ($err !== '' ? ': ' . $err : '');
    $curlBin = '/usr/bin/curl';
    if (!is_executable($curlBin) || !function_exists('proc_open')) {
        throw new RuntimeException('Download Anvisa falhou via PHP cURL ' . $phpError);
    }

    $cmd = [$curlBin, '--fail', '--location', '--silent', '--show-error', '--retry', '3', '--retry-delay', '2', '--connect-timeout', '30', '--max-time', '240', '--user-agent', 'Farmacia-SuperAmplitude/1.0'];
    if ($ca !== '') { $cmd[] = '--cacert'; $cmd[] = $ca; }
    $cmd[] = '--output'; $cmd[] = $tmp; $cmd[] = $url;
    $pipes = [];
    $proc = proc_open($cmd, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!is_resource($proc)) throw new RuntimeException('Falha ao iniciar curl do sistema após erro ' . $phpError);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0 || !is_file($tmp) || filesize($tmp) < 1024) {
        @unlink($tmp);
        $cliError = trim((string)$stderr);
        if ($cliError === '') $cliError = trim((string)$stdout);
        throw new RuntimeException('Download Anvisa falhou; PHP=' . $phpError . '; CLI=' . ($cliError !== '' ? $cliError : 'exit ' . $code));
    }
    echo "ANVISA_DOWNLOAD_FALLBACK=system_curl\n";
};

try {
    $run = $db->prepare("INSERT INTO import_runs(source,status,message) VALUES('ANVISA','running','download')");
    $run->execute();
    $rid = (int)$db->lastInsertId();

    $downloadSecure($url, $tmp);
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
