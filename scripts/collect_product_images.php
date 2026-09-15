<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (PHP_SAPI !== 'cli') exit("CLI only\n");

$stateDir = private_state_dir();
$manifestPath = trim((string)env('IMAGE_MANIFEST_PATH', $stateDir . '/image-manifest.json'));
$cursorPath = $stateDir . '/image-discovery-state.json';
$limit = max(1, min(250, (int)env('IMAGE_DISCOVERY_LIMIT', 25)));
$delayUs = max(150000, min(2000000, (int)env('IMAGE_DISCOVERY_DELAY_US', 450000)));
$timeout = max(8, min(45, (int)env('IMAGE_DISCOVERY_TIMEOUT', 18)));
$maxCandidates = max(1, min(8, (int)env('IMAGE_DISCOVERY_CANDIDATES', 4)));
$sourceHost = 'https://www.drogaraia.com.br';

function norm_text(string $s): string {
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = mb_strtolower($s, 'UTF-8');
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = preg_replace('/[^a-z0-9]+/i', ' ', $s) ?: '';
    return trim(preg_replace('/\s+/', ' ', $s) ?: '');
}
function reg_digits(string $s): string { return preg_replace('/\D+/', '', $s) ?: ''; }
function http_get_text(string $url, int $timeout): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; FarmaciaSuperAmplitude-ProductImageDiscovery/1.0; +https://farmacia.superamplitude.com)',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.7', 'Accept-Language: pt-BR,pt;q=0.9,en;q=0.5'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) throw new RuntimeException('HTTP ' . $code . ($err ? ': ' . $err : ''));
    if ($type !== '' && !str_contains($type, 'text/html')) throw new RuntimeException('content-type inesperado: ' . $type);
    return (string)$body;
}
function extract_links(string $html, string $base): array {
    $out = [];
    if (preg_match_all('~href=["\']([^"\']+\.html(?:\?[^"\']*)?)["\']~i', $html, $m)) {
        foreach ($m[1] as $href) {
            $href = html_entity_decode((string)$href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (str_starts_with($href, '//')) $href = 'https:' . $href;
            elseif (str_starts_with($href, '/')) $href = $base . $href;
            elseif (!preg_match('~^https://~i', $href)) continue;
            if (!str_starts_with($href, $base . '/')) continue;
            $href = preg_replace('/\?.*$/', '', $href) ?: $href;
            if (str_contains(strtolower($href), '/kit-')) continue;
            $out[$href] = true;
        }
    }
    return array_keys($out);
}
function extract_title(string $html): string {
    if (preg_match('~<h1[^>]*>(.*?)</h1>~is', $html, $m)) return trim(strip_tags(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if (preg_match('~<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']~i', $html, $m)) return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) return trim(strip_tags(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    return '';
}
function extract_registration(string $html): string {
    $text = preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?: '';
    if (preg_match('/Registro\s*MS\s*[:\-]?\s*([0-9.\-]{8,24})/iu', $text, $m)) return reg_digits($m[1]);
    if (preg_match('/MS\s*[:\-]?\s*([0-9.\-]{10,24})/iu', $text, $m)) return reg_digits($m[1]);
    return '';
}
function extract_image(string $html): string {
    if (preg_match('~https%3A%2F%2Fproduct-data\.raiadrogasil\.io%2Fimages%2F[^&"\'<> ]+~i', $html, $m)) {
        $u = urldecode($m[0]);
        if (preg_match('~^https://product-data\.raiadrogasil\.io/images/[A-Za-z0-9._%-]+$~', $u)) return $u;
    }
    if (preg_match('~https://product-data\.raiadrogasil\.io/images/[A-Za-z0-9._%-]+~i', $html, $m)) return html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (preg_match('~<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']~i', $html, $m)) {
        $u = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('~^https://~i', $u)) return $u;
    }
    return '';
}
function token_score(string $a, string $b): float {
    $aa = array_values(array_filter(explode(' ', norm_text($a)), fn($x)=>strlen($x) >= 2));
    $bb = array_values(array_filter(explode(' ', norm_text($b)), fn($x)=>strlen($x) >= 2));
    if (!$aa || !$bb) return 0.0;
    $sa = array_unique($aa); $sb = array_unique($bb);
    $inter = count(array_intersect($sa, $sb));
    return $inter / max(1, min(count($sa), count($sb)));
}

$cursor = 0;
if (is_file($cursorPath)) {
    $s = json_decode((string)file_get_contents($cursorPath), true);
    if (is_array($s)) $cursor = max(0, (int)($s['last_id'] ?? 0));
}

$existing = ['items'=>[]];
if (is_file($manifestPath)) {
    $raw = json_decode((string)file_get_contents($manifestPath), true);
    if (is_array($raw)) $existing = isset($raw['items']) && is_array($raw['items']) ? $raw : ['items'=>$raw];
}
$itemsByReg = [];
foreach (($existing['items'] ?? []) as $it) {
    if (!is_array($it)) continue;
    $r = reg_digits((string)($it['registration'] ?? ''));
    if ($r !== '') $itemsByReg[$r] = $it;
}

$sql = 'SELECT id,registration,product_name,active_ingredient,company,image_url FROM medications WHERE id>? AND (image_url IS NULL OR TRIM(image_url)="") ORDER BY id LIMIT ?';
$st = $db->prepare($sql);
$st->bindValue(1, $cursor, PDO::PARAM_INT);
$st->bindValue(2, $limit, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();
if (!$rows && $cursor > 0) {
    $cursor = 0;
    $st->bindValue(1, 0, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
}

$stats = ['seen'=>0,'search_ok'=>0,'candidates'=>0,'matched_reg'=>0,'matched_title'=>0,'manifest_added'=>0,'not_found'=>0,'errors'=>0];
$lastId = $cursor;
foreach ($rows as $row) {
    $stats['seen']++;
    $lastId = max($lastId, (int)$row['id']);
    $registration = reg_digits((string)$row['registration']);
    if ($registration === '' || isset($itemsByReg[$registration])) continue;
    $query = trim((string)$row['product_name']);
    if ($query === '') continue;
    $searchUrl = $sourceHost . '/search?w=' . rawurlencode($query);
    try {
        $html = http_get_text($searchUrl, $timeout);
        $stats['search_ok']++;
        $links = extract_links($html, $sourceHost);
        if (!$links) { $stats['not_found']++; usleep($delayUs); continue; }
        $best = null; $bestScore = 0.0;
        foreach (array_slice($links, 0, $maxCandidates) as $link) {
            $stats['candidates']++;
            usleep($delayUs);
            try { $p = http_get_text($link, $timeout); } catch (Throwable $e) { continue; }
            $title = extract_title($p);
            $pageReg = extract_registration($p);
            $image = extract_image($p);
            if ($image === '') continue;
            if ($pageReg !== '' && $pageReg === $registration) {
                $best = ['registration'=>$registration,'source_url'=>$image,'source_page'=>$link,'source'=>'drogaraia','matched_by'=>'registration','matched_title'=>$title];
                $stats['matched_reg']++;
                break;
            }
            $score = token_score($query, $title);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = ['registration'=>$registration,'source_url'=>$image,'source_page'=>$link,'source'=>'drogaraia','matched_by'=>'title','match_score'=>round($score,3),'matched_title'=>$title];
            }
        }
        if ($best && (($best['matched_by'] ?? '') === 'registration' || (float)($best['match_score'] ?? 0) >= 0.82)) {
            if (($best['matched_by'] ?? '') === 'title') $stats['matched_title']++;
            $itemsByReg[$registration] = $best;
            $stats['manifest_added']++;
            echo 'IMAGE_DISCOVERY_MATCH id=' . (int)$row['id'] . ' reg=' . $registration . ' by=' . $best['matched_by'] . ' source=' . $best['source_page'] . "\n";
        } else {
            $stats['not_found']++;
        }
    } catch (Throwable $e) {
        $stats['errors']++;
        fwrite(STDERR, 'IMAGE_DISCOVERY_FAIL id=' . (int)$row['id'] . ' reg=' . $registration . ' reason=' . preg_replace('/\s+/', ' ', $e->getMessage()) . "\n");
    }
    usleep($delayUs);
}

$manifest = [
    'generated_at'=>gmdate(DATE_ATOM),
    'source_note'=>'Public product packshots discovered from reference pharmacy product pages; source page retained for audit.',
    'items'=>array_values($itemsByReg),
];
$tmp = $manifestPath . '.tmp.' . getmypid();
if (file_put_contents($tmp, json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) === false) throw new RuntimeException('Falha ao gravar manifesto');
rename($tmp, $manifestPath);
@chmod($manifestPath, 0660);
file_put_contents($cursorPath, json_encode(['last_id'=>$lastId,'updated_at'=>gmdate(DATE_ATOM)], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
@chmod($cursorPath, 0660);

echo 'IMAGE_DISCOVERY_OK ';
foreach ($stats as $k=>$v) echo $k . '=' . $v . ' ';
echo 'manifest_items=' . count($itemsByReg) . ' last_id=' . $lastId . ' manifest=' . $manifestPath . "\n";
