<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

function load_env_file(string $file): void
{
    if (!is_file($file)) return;

    // Parse the full file first so the last occurrence of a duplicated key wins.
    // External process environment still has precedence when it is non-empty.
    $values = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        if ($k === '' || preg_match('/^[A-Z_][A-Z0-9_]*$/i', $k) !== 1) continue;
        $values[$k] = trim($v, " \t\n\r\0\x0B\"'");
    }

    foreach ($values as $k => $v) {
        $current = getenv($k);
        if ($current === false || trim((string)$current) === '') {
            putenv($k . '=' . $v);
        }
    }
}

$derivedState = '/home/superamplitude-farmacia/.farmacia';
if (preg_match('#^/home/([^/]+)/htdocs/#', APP_ROOT, $m)) $derivedState = '/home/' . $m[1] . '/.farmacia';
load_env_file($derivedState . '/.env');
load_env_file(APP_ROOT . '/.env');

function env(string $key, mixed $default = null): mixed
{
    $v = getenv($key);
    return $v === false ? $default : $v;
}

function private_state_dir(): string
{
    global $derivedState;
    return rtrim((string)env('PRIVATE_STATE_DIR', $derivedState), '/');
}

function h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string
{
    $base = rtrim((string)env('APP_BASE', '/'), '/');
    return $base . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function absolute_url(string $path = ''): string
{
    return rtrim((string)env('APP_URL', 'https://farmacia.superamplitude.com'), '/') . url($path);
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['_csrf'];
}

function csrf_check(): void
{
    if (!hash_equals($_SESSION['_csrf'] ?? '', $_POST['_csrf'] ?? '')) {
        http_response_code(419);
        exit('CSRF inválido');
    }
}

function order_public_token(): string
{
    return bin2hex(random_bytes(24));
}

function med_image(array $m): string
{
    if (!empty($m['store_image_url'])) return (string)$m['store_image_url'];
    if (!empty($m['image_url'])) return (string)$m['image_url'];
    return url('assets/medicine.svg');
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('X-Frame-Options: SAMEORIGIN');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('farmacia_session');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']),
        'samesite' => 'Lax',
        'path' => env('APP_BASE', '/') ?: '/',
    ]);
    session_start();
}

require APP_ROOT . '/src/Database.php';
require APP_ROOT . '/src/Schema.php';
require APP_ROOT . '/src/Auth.php';
require APP_ROOT . '/src/Catalog.php';
require APP_ROOT . '/src/Pharmacy.php';
require APP_ROOT . '/src/AiAssistant.php';
require APP_ROOT . '/src/R2Storage.php';
require APP_ROOT . '/src/PaymentGateway.php';

$db = Database::connection();
Schema::migrate($db);
Auth::bootstrapAdmin($db);
$defaultPharmacy = Pharmacy::ensureDefault($db);
Auth::bootstrapStoreAdmin($db, (int)$defaultPharmacy['id']);
