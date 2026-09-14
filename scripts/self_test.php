<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
if (PHP_SAPI !== 'cli') exit("CLI only\n");

$checks = [];
$failed = [];
$warnings = [];

$add = static function(string $name, bool $ok, mixed $detail = null, bool $required = true) use (&$checks, &$failed, &$warnings): void {
    $checks[$name] = ['ok' => $ok, 'required' => $required, 'detail' => $detail];
    if (!$ok) {
        if ($required) $failed[] = $name;
        else $warnings[] = $name;
    }
};

try {
    $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $add('database_connection', true, $driver);
} catch (Throwable $e) {
    $add('database_connection', false, $e->getMessage());
}

foreach (['pharmacies','medications','pharmacy_products','users','orders','prescriptions','audit_logs'] as $table) {
    try {
        $count = (int)$db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        $add('table_' . $table, true, $count);
    } catch (Throwable $e) {
        $add('table_' . $table, false, $e->getMessage());
    }
}

try {
    $meds = (int)$db->query('SELECT COUNT(*) FROM medications')->fetchColumn();
    $add('anvisa_catalog_nonempty', $meds > 0, $meds);
} catch (Throwable $e) {
    $add('anvisa_catalog_nonempty', false, $e->getMessage());
}

$state = private_state_dir();
$envFile = $state . '/.env';
$uploads = $state . '/uploads';
$add('private_state_path', preg_match('#^/home/[^/]+/\.farmacia$#', $state) === 1, $state);
$add('private_env_present', is_file($envFile), $envFile);
$add('private_env_readable', is_readable($envFile), $envFile);
$add('uploads_directory', is_dir($uploads), $uploads);
$add('uploads_writable', is_dir($uploads) && is_writable($uploads), $uploads);

$requiredExt = ['pdo','pdo_sqlite','curl','mbstring','fileinfo'];
foreach ($requiredExt as $ext) $add('php_ext_' . $ext, extension_loaded($ext), $ext);

$appKey = trim((string)env('APP_KEY', ''));
$adminPassword = (string)env('SUPERADMIN_PASSWORD', '');
$add('app_key_configured', strlen($appKey) >= 32, $appKey === '' ? 'missing' : 'configured');
$add('superadmin_password_configured', strlen($adminPassword) >= 14, $adminPassword === '' ? 'missing' : 'configured');

$r2 = R2Storage::config();
$add('r2_public_config', $r2['account_id'] !== '' && $r2['bucket'] !== '' && $r2['public_base_url'] !== '', [
    'account' => $r2['account_id'],
    'bucket' => $r2['bucket'],
    'public' => $r2['public_base_url'],
]);
$add('r2_write_credentials', R2Storage::readyForWrite(), R2Storage::readyForWrite() ? 'configured' : 'pending_private_credentials', false);
$add('ai_provider_configured', (string)env('AI_ENABLED','0') !== '1' || (trim((string)env('AI_API_URL','')) !== '' && trim((string)env('AI_API_KEY','')) !== '' && trim((string)env('AI_MODEL','')) !== ''), (string)env('AI_ENABLED','0') === '1' ? 'enabled' : 'disabled_fallback_mode', false);

$result = [
    'ok' => $failed === [],
    'failed' => $failed,
    'warnings' => $warnings,
    'checks' => $checks,
    'time' => gmdate(DATE_ATOM),
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
exit($failed === [] ? 0 : 1);
