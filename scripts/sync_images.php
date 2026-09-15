<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (PHP_SAPI !== 'cli') exit("CLI only\n");

$manifestUrl = trim((string)env('IMAGE_MANIFEST_URL', ''));
$manifestPath = trim((string)env('IMAGE_MANIFEST_PATH', private_state_dir() . '/image-manifest.json'));
$publicBase = rtrim((string)env('IMAGE_BASE_URL', 'https://img.farmacia.superamplitude.com'), '/');
$maxBytes = max(256 * 1024, min(15 * 1024 * 1024, (int)env('IMAGE_MAX_BYTES', 8 * 1024 * 1024)));
$timeout = max(5, min(90, (int)env('IMAGE_FETCH_TIMEOUT', 25)));

function image_manifest_raw(string $url, string $path): string
{
    if ($url !== '') {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'FarmaciaSuperAmplitude-ImageSync/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            throw new RuntimeException('Falha ao obter manifesto HTTP ' . $code . ($err ? ': ' . $err : ''));
        }
        return (string)$body;
    }
    if ($path !== '' && is_file($path)) {
        $raw = file_get_contents($path);
        if ($raw === false) throw new RuntimeException('Falha ao ler manifesto local');
        return $raw;
    }
    return '';
}

function fetch_image(string $url, int $maxBytes, int $timeout): array
{
    if (!preg_match('#^https://#i', $url)) throw new RuntimeException('Imagem remota deve usar HTTPS');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; FarmaciaSuperAmplitude/1.0; +https://farmacia.superamplitude.com)',
        CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/png,image/jpeg,image/*;q=0.8'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = strtolower(trim((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE)));
    $effective = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) throw new RuntimeException('download HTTP ' . $code . ($err ? ': ' . $err : ''));
    if (strlen((string)$body) > $maxBytes) throw new RuntimeException('imagem excede limite de tamanho');

    $fi = new finfo(FILEINFO_MIME_TYPE);
    $mime = strtolower((string)$fi->buffer((string)$body));
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!isset($allowed[$mime])) throw new RuntimeException('tipo de imagem não permitido: ' . ($mime ?: $type));
    if (@getimagesizefromstring((string)$body) === false) throw new RuntimeException('arquivo não é uma imagem válida');
    return [(string)$body, $mime, $allowed[$mime], $effective];
}

$raw = image_manifest_raw($manifestUrl, $manifestPath);
if ($raw === '') {
    echo "IMAGE_SYNC_SKIPPED manifest_missing path={$manifestPath}\n";
    exit(0);
}

$decoded = json_decode($raw, true);
if (!is_array($decoded)) throw new RuntimeException('Manifesto de imagens inválido');
$items = isset($decoded['items']) && is_array($decoded['items']) ? $decoded['items'] : $decoded;

$find = $db->prepare('SELECT id,image_url FROM medications WHERE registration=? LIMIT 1');
$update = $db->prepare('UPDATE medications SET image_url=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
$stats = ['seen'=>0,'matched'=>0,'uploaded'=>0,'linked'=>0,'skipped'=>0,'failed'=>0];

foreach ($items as $item) {
    if (!is_array($item)) continue;
    $stats['seen']++;
    $registration = trim((string)($item['registration'] ?? ''));
    $source = trim((string)($item['source_url'] ?? $item['image_url'] ?? $item['url'] ?? $item['path'] ?? ''));
    if ($registration === '' || $source === '') { $stats['skipped']++; continue; }

    $find->execute([$registration]);
    $med = $find->fetch();
    if (!$med) { $stats['skipped']++; continue; }
    $stats['matched']++;

    $existing = trim((string)($med['image_url'] ?? ''));
    if ($existing !== '' && str_starts_with($existing, $publicBase . '/')) { $stats['skipped']++; continue; }

    try {
        if (str_starts_with($source, $publicBase . '/')) {
            $update->execute([$source, (int)$med['id']]);
            $stats['linked'] += $update->rowCount();
            continue;
        }

        if (!R2Storage::readyForWrite()) throw new RuntimeException('R2 sem credenciais de escrita');
        [$bytes, $mime, $ext] = fetch_image($source, $maxBytes, $timeout);
        $safeReg = preg_replace('/[^A-Za-z0-9._-]+/', '-', $registration) ?: (string)$med['id'];
        $hash = substr(hash('sha256', $bytes), 0, 24);
        $key = 'products/' . $safeReg . '/' . $hash . '.' . $ext;
        $url = R2Storage::put($key, $bytes, $mime);
        $update->execute([$url, (int)$med['id']]);
        $stats['uploaded']++;
    } catch (Throwable $e) {
        $stats['failed']++;
        fwrite(STDERR, 'IMAGE_SYNC_ITEM_FAIL registration=' . $registration . ' reason=' . preg_replace('/\s+/', ' ', $e->getMessage()) . "\n");
    }
}

echo 'IMAGE_SYNC_OK ';
foreach ($stats as $k => $v) echo $k . '=' . $v . ' ';
echo 'base=' . $publicBase . "\n";
if ($stats['failed'] > 0 && $stats['uploaded'] === 0 && $stats['linked'] === 0) exit(2);
