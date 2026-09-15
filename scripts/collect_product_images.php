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
$raiaHost = 'https://www.drogaraia.com.br';
$dspHost = 'https://www.drogariasaopaulo.com.br';

function norm_text(string $s): string {
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = mb_strtolower($s, 'UTF-8');
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = preg_replace('/[^a-z0-9]+/i', ' ', $s) ?: '';
    return trim(preg_replace('/\s+/', ' ', $s) ?: '');
}
function reg_digits(string $s): string { return preg_replace('/\D+/', '', $s) ?: ''; }
function token_score(string $a, string $b): float {
    $aa = array_values(array_filter(explode(' ', norm_text($a)), fn($x)=>strlen($x) >= 2));
    $bb = array_values(array_filter(explode(' ', norm_text($b)), fn($x)=>strlen($x) >= 2));
    if (!$aa || !$bb) return 0.0;
    $sa = array_unique($aa); $sb = array_unique($bb);
    $inter = count(array_intersect($sa, $sb));
    return $inter / max(1, min(count($sa), count($sb)));
}
function http_get(string $url, int $timeout, string $accept): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/153 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept: ' . $accept, 'Accept-Language: pt-BR,pt;q=0.9,en;q=0.5'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    $effective = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) throw new RuntimeException('HTTP ' . $code . ($err ? ': ' . $err : ''));
    return [(string)$body, $type, $effective, $code];
}
function http_get_text(string $url, int $timeout): string {
    [$body,$type] = http_get($url,$timeout,'text/html,application/xhtml+xml;q=0.9,*/*;q=0.7');
    if ($type !== '' && !str_contains($type, 'text/html')) throw new RuntimeException('content-type inesperado: ' . $type);
    return $body;
}
function http_get_json(string $url, int $timeout): array {
    [$body,$type] = http_get($url,$timeout,'application/json,text/plain;q=0.9,*/*;q=0.5');
    $json = json_decode($body, true);
    if (!is_array($json)) throw new RuntimeException('JSON inválido' . ($type ? ' tipo=' . $type : ''));
    return $json;
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
function extract_raia_image(string $html): string {
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
function recursive_contains_registration(mixed $v, string $registration): bool {
    if (is_string($v) || is_int($v) || is_float($v)) return reg_digits((string)$v) === $registration;
    if (!is_array($v)) return false;
    foreach ($v as $x) if (recursive_contains_registration($x, $registration)) return true;
    return false;
}
function dsp_candidates(string $query, string $registration, int $timeout, string $host): array {
    $variants = [$query];
    $parts = array_values(array_filter(explode(' ', norm_text($query))));
    if (count($parts) > 6) $variants[] = implode(' ', array_slice($parts, 0, 6));
    $out = [];
    foreach (array_unique($variants) as $q) {
        $url = $host . '/api/catalog_system/pub/products/search/' . rawurlencode($q) . '?_from=0&_to=7';
        try { $products = http_get_json($url, $timeout); } catch (Throwable $e) { continue; }
        foreach ($products as $p) {
            if (!is_array($p)) continue;
            $title = trim((string)($p['productName'] ?? $p['productTitle'] ?? ''));
            $link = trim((string)($p['link'] ?? ''));
            $img = '';
            foreach (($p['items'] ?? []) as $item) {
                if (!is_array($item)) continue;
                foreach (($item['images'] ?? []) as $im) {
                    if (!is_array($im)) continue;
                    $u = trim((string)($im['imageUrl'] ?? ''));
                    if (preg_match('~^https://~i', $u)) { $img = $u; break 2; }
                }
            }
            if ($title === '' || $img === '') continue;
            $exactReg = recursive_contains_registration($p, $registration);
            $score = token_score($query, $title);
            $out[] = [
                'registration'=>$registration,
                'source_url'=>$img,
                'source_page'=>$link !== '' ? $link : $url,
                'source'=>'drogariasaopaulo',
                'matched_by'=>$exactReg ? 'registration' : 'title',
                'match_score'=>round($score,3),
                'matched_title'=>$title,
            ];
        }
        if ($out) break;
    }
    usort($out, static function(array $a,array $b): int {
        $ar = ($a['matched_by'] ?? '') === 'registration' ? 1 : 0;
        $br = ($b['matched_by'] ?? '') === 'registration' ? 1 : 0;
        if ($ar !== $br) return $br <=> $ar;
        return ((float)($b['match_score'] ?? 0)) <=> ((float)($a['match_score'] ?? 0));
    });
    return $out;
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
if (!$itemsByReg && $cursor > 0) $cursor = 0;

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

$stats = ['seen'=>0,'dsp_ok'=>0,'raia_ok'=>0,'candidates'=>0,'matched_reg'=>0,'matched_title'=>0,'manifest_added'=>0,'not_found'=>0,'errors'=>0];
$lastId = $cursor;
$raiaBlocked = false;
foreach ($rows as $row) {
    $stats['seen']++;
    $lastId = max($lastId, (int)$row['id']);
    $registration = reg_digits((string)$row['registration']);
    if ($registration === '' || isset($itemsByReg[$registration])) continue;
    $query = trim((string)$row['product_name']);
    if ($query === '') continue;
    $best = null;

    try {
        $dsp = dsp_candidates($query, $registration, $timeout, $dspHost);
        if ($dsp) {
            $stats['dsp_ok']++;
            $stats['candidates'] += count($dsp);
            $cand = $dsp[0];
            if (($cand['matched_by'] ?? '') === 'registration' || (float)($cand['match_score'] ?? 0) >= 0.86) $best = $cand;
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'IMAGE_DISCOVERY_DSP_FAIL id=' . (int)$row['id'] . ' reason=' . preg_replace('/\s+/', ' ', $e->getMessage()) . "\n");
    }

    if (!$best && !$raiaBlocked) {
        $searchUrl = $raiaHost . '/search?w=' . rawurlencode($query);
        try {
            $html = http_get_text($searchUrl, $timeout);
            $stats['raia_ok']++;
            $links = extract_links($html, $raiaHost);
            $bestScore = 0.0;
            foreach (array_slice($links, 0, $maxCandidates) as $link) {
                $stats['candidates']++;
                usleep($delayUs);
                try { $p = http_get_text($link, $timeout); } catch (Throwable $e) { continue; }
                $title = extract_title($p);
                $pageReg = extract_registration($p);
                $image = extract_raia_image($p);
                if ($image === '') continue;
                if ($pageReg !== '' && $pageReg === $registration) {
                    $best = ['registration'=>$registration,'source_url'=>$image,'source_page'=>$link,'source'=>'drogaraia','matched_by'=>'registration','matched_title'=>$title];
                    break;
                }
                $score = token_score($query, $title);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = ['registration'=>$registration,'source_url'=>$image,'source_page'=>$link,'source'=>'drogaraia','matched_by'=>'title','match_score'=>round($score,3),'matched_title'=>$title];
                }
            }
            if ($best && ($best['matched_by'] ?? '') === 'title' && (float)($best['match_score'] ?? 0) < 0.82) $best = null;
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'HTTP 403')) $raiaBlocked = true;
            else fwrite(STDERR, 'IMAGE_DISCOVERY_RAIA_FAIL id=' . (int)$row['id'] . ' reason=' . preg_replace('/\s+/', ' ', $e->getMessage()) . "\n");
        }
    }

    if ($best) {
        if (($best['matched_by'] ?? '') === 'registration') $stats['matched_reg']++; else $stats['matched_title']++;
        $itemsByReg[$registration] = $best;
        $stats['manifest_added']++;
        echo 'IMAGE_DISCOVERY_MATCH id=' . (int)$row['id'] . ' reg=' . $registration . ' source=' . $best['source'] . ' by=' . $best['matched_by'] . ' score=' . (string)($best['match_score'] ?? '1') . ' page=' . $best['source_page'] . "\n";
    } else {
        $stats['not_found']++;
    }
    usleep($delayUs);
}

$manifest = [
    'generated_at'=>gmdate(DATE_ATOM),
    'source_note'=>'Product packshot source URLs discovered from the authorized reference pharmacy catalogs; source page retained for audit.',
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
