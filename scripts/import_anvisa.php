<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/AnvisaDownloader.php';

if (PHP_SAPI !== 'cli') exit("CLI only\n");

$url = trim((string)env('ANVISA_CSV_URL', ''));
if ($url === '') {
    fwrite(STDERR, "ANVISA_FAIL URL não configurada\n");
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
$skipped = 0;
$fh = null;

try {
    $run = $db->prepare("INSERT INTO import_runs(source,status,message) VALUES('ANVISA','running','download')");
    $run->execute();
    $rid = (int)$db->lastInsertId();

    AnvisaDownloader::download($url, $tmp);
    if (!is_file($tmp) || filesize($tmp) < 1024) {
        throw new RuntimeException('CSV Anvisa vazio ou incompleto');
    }

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

    $indexOf = static function(array $names) use ($keys, $norm): int|false {
        foreach ($names as $name) {
            $i = array_search($norm($name), $keys, true);
            if ($i !== false) return $i;
        }
        return false;
    };
    $pick = static function(array $row, array $names) use ($indexOf): string {
        $i = $indexOf($names);
        return $i !== false && array_key_exists($i, $row) ? trim((string)$row[$i]) : '';
    };

    $required = [
        'registration' => ['NU_REGISTRO_PRODUTO', 'NUMERO_REGISTRO_PRODUTO', 'NUMERO_REGISTRO', 'REGISTRO'],
        'product_name' => ['NO_PRODUTO', 'NOME_PRODUTO', 'NOME_COMERCIAL', 'PRODUTO'],
    ];
    foreach ($required as $field => $variants) {
        if ($indexOf($variants) === false) {
            throw new RuntimeException('CSV Anvisa incompatível: campo obrigatório ausente ' . $field);
        }
    }

    $schemaVersion = in_array('NO_PRODUTO', $keys, true) && in_array('NU_REGISTRO_PRODUTO', $keys, true)
        ? 'TA_CONSULTA_CURRENT'
        : 'TA_CONSULTA_COMPAT';
    echo "ANVISA_SCHEMA={$schemaVersion} columns=" . count($keys) . "\n";

    $up = $db->prepare(<<<'SQL'
INSERT INTO medications(
    registration,product_name,active_ingredient,company,regulatory_category,
    therapeutic_class,presentation,registration_status,
    requires_prescription,retain_prescription,controlled,remote_delivery_allowed,
    review_required,bula_url,source,source_updated_at
) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,'ANVISA',CURRENT_TIMESTAMP)
ON CONFLICT(registration) DO UPDATE SET
    product_name=CASE WHEN excluded.product_name<>'' THEN excluded.product_name ELSE medications.product_name END,
    active_ingredient=CASE WHEN excluded.active_ingredient<>'' THEN excluded.active_ingredient ELSE medications.active_ingredient END,
    company=CASE WHEN excluded.company<>'' THEN excluded.company ELSE medications.company END,
    regulatory_category=CASE WHEN excluded.regulatory_category<>'' THEN excluded.regulatory_category ELSE medications.regulatory_category END,
    therapeutic_class=CASE WHEN excluded.therapeutic_class<>'' THEN excluded.therapeutic_class ELSE medications.therapeutic_class END,
    presentation=CASE WHEN excluded.presentation<>'' THEN excluded.presentation ELSE medications.presentation END,
    registration_status=CASE WHEN excluded.registration_status<>'' THEN excluded.registration_status ELSE medications.registration_status END,
    requires_prescription=MAX(medications.requires_prescription, excluded.requires_prescription),
    retain_prescription=MAX(medications.retain_prescription, excluded.retain_prescription),
    controlled=MAX(medications.controlled, excluded.controlled),
    remote_delivery_allowed=MIN(medications.remote_delivery_allowed, excluded.remote_delivery_allowed),
    review_required=MAX(medications.review_required, excluded.review_required),
    bula_url=CASE WHEN excluded.bula_url<>'' THEN excluded.bula_url ELSE medications.bula_url END,
    source='ANVISA',
    source_updated_at=CURRENT_TIMESTAMP
SQL);

    $db->beginTransaction();
    while (($row = fgetcsv($fh, 0, ';')) !== false) {
        $seen++;

        $reg = $pick($row, ['NU_REGISTRO_PRODUTO', 'NUMERO_REGISTRO_PRODUTO', 'NUMERO_REGISTRO', 'REGISTRO']);
        $name = $pick($row, ['NO_PRODUTO', 'NOME_PRODUTO', 'NOME_COMERCIAL', 'PRODUTO']);
        if ($reg === '' || $name === '') {
            $skipped++;
            continue;
        }

        $active = $pick($row, ['SUBSTANCIAS_MEDICAMENTOS', 'PRINCIPIO_ATIVO', 'PRINCIPIO ATIVO', 'SUBSTANCIA']);
        $company = $pick($row, ['NO_RAZAO_SOCIAL_EMPRESA', 'EMPRESA_DETENTORA_REGISTRO', 'RAZAO_SOCIAL', 'EMPRESA', 'LABORATORIO']);
        $category = $pick($row, ['DS_TIPO_CATEGORIA_REGULATORIA', 'CATEGORIA_REGULATORIA', 'CATEGORIA']);
        $atc = $pick($row, ['CO_ATC', 'CLASSE_TERAPEUTICA', 'CLASSE']);
        $tarja = $pick($row, ['CO_TARJA', 'TARJA']);
        $restriction = $pick($row, ['CO_RESTRICAO', 'RESTRICAO']);
        $reference = $pick($row, ['DS_REFERENCIA', 'REFERENCIA']);
        $complement = $pick($row, ['COMPLEMENTO', 'APRESENTACAO', 'APRESENTACAO_PRODUTO']);
        $physicalForm = $pick($row, ['CO_FORMA_FISICA', 'FORMA_FARMACEUTICA', 'FORMA']);
        $presentations = $pick($row, ['NUMERO_APRESENTACOES']);
        $presentationStatus = $pick($row, ['TP_SITUACAO_APRESENTACAO']);

        $validity = $pick($row, ['VALIDADE_SITUACAO', 'SITUACAO_REGISTRO']);
        $subjectStatus = $pick($row, ['SITUACAO_ASSUNTO', 'CO_SITUACAO_ASSUNTO_DOC']);
        $statusParts = array_values(array_filter([
            $validity,
            $subjectStatus !== '' ? 'ASSUNTO=' . $subjectStatus : '',
            $presentationStatus !== '' ? 'APRESENTACAO=' . $presentationStatus : '',
        ], static fn(string $v): bool => $v !== ''));
        $status = implode(' | ', $statusParts);

        $presentationParts = array_values(array_filter([
            $complement,
            $reference !== '' ? 'REFERENCIA=' . $reference : '',
            $physicalForm !== '' ? 'FORMA=' . $physicalForm : '',
            $presentations !== '' ? 'APRESENTACOES=' . $presentations : '',
            $tarja !== '' ? 'TARJA=' . $tarja : '',
            $restriction !== '' ? 'RESTRICAO=' . $restriction : '',
        ], static fn(string $v): bool => $v !== ''));
        $presentation = implode(' | ', $presentationParts);

        // Nunca inferimos significado clínico de códigos numéricos desconhecidos.
        // Textos regulatórios explícitos entram no classificador; casos ambíguos
        // permanecem review_required=1 e produtos de farmácia nascem inativos.
        $classificationContext = implode(' ', array_filter([
            $atc,
            $tarja,
            $restriction,
            $validity,
            $subjectStatus,
            $presentationStatus,
        ], static fn(string $v): bool => $v !== ''));
        [$rx, $retain, $controlled, $remote, $review] = Catalog::classify($category, $classificationContext, $presentation);

        $up->execute([
            $reg,
            $name,
            $active,
            $company,
            $category,
            $atc,
            $presentation,
            $status,
            $rx,
            $retain,
            $controlled,
            $remote,
            $review,
            (string)env('BULARIO_URL', 'https://consultas.anvisa.gov.br/#/bulario/'),
        ]);
        $written++;

        if ($written % 1000 === 0) {
            $db->commit();
            echo "ANVISA_PROGRESS written={$written} seen={$seen}\n";
            $db->beginTransaction();
        }
    }

    if ($db->inTransaction()) $db->commit();
    fclose($fh);
    $fh = null;

    if ($written === 0) throw new RuntimeException('Nenhum medicamento válido foi importado');

    $medicationCount = (int)$db->query('SELECT COUNT(*) FROM medications')->fetchColumn();
    if ($medicationCount === 0) throw new RuntimeException('Catálogo permaneceu vazio após importação');

    $done = $db->prepare("UPDATE import_runs SET status='done',rows_seen=?,rows_written=?,message=?,finished_at=CURRENT_TIMESTAMP WHERE id=?");
    $done->execute([$seen, $written, 'ok schema=' . $schemaVersion . ' skipped=' . $skipped, $rid]);
    @unlink($tmp);

    echo "ANVISA_OK seen={$seen} written={$written} skipped={$skipped} medications={$medicationCount} schema={$schemaVersion}\n";
    exit(0);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    if (is_resource($fh)) fclose($fh);
    @unlink($tmp);

    if ($rid > 0) {
        try {
            $failed = $db->prepare("UPDATE import_runs SET status='failed',rows_seen=?,rows_written=?,message=?,finished_at=CURRENT_TIMESTAMP WHERE id=?");
            $failed->execute([$seen, $written, substr($e->getMessage(), 0, 500), $rid]);
        } catch (Throwable) {
        }
    }

    fwrite(STDERR, 'ANVISA_FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
