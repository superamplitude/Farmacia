<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
if (PHP_SAPI !== 'cli') exit("CLI only\n");

$checks = [];
$failed = [];

$add = static function(string $name, bool $ok, mixed $detail = null) use (&$checks, &$failed): void {
    $checks[$name] = ['ok' => $ok, 'detail' => $detail];
    if (!$ok) $failed[] = $name;
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

$envFile = '/home/superamplitude/.farmacia/.env';
$uploads = '/home/superamplitude/.farmacia/uploads';
$add('private_env_present', is_file($envFile), $envFile);
$add('private_env_readable', is_readable($envFile), $envFile);
$add('uploads_directory', is_dir($uploads), $uploads);
$add('uploads_writable', is_dir($uploads) && is_writable($uploads), $uploads);

$requiredExt = ['pdo','pdo_sqlite','curl','mbstring','fileinfo'];
foreach ($requiredExt as $ext) $add('php_ext_' . $ext, extension_loaded($ext), $ext);

$r2 = R2Storage::config();
$add('r2_public_config', $r2['account_id'] !== '' && $r2['bucket'] !== '' && $r2['public_base_url'] !== '', [
    'account' => $r2['account_id'],
    'bucket' => $r2['bucket'],
    'public' => $r2['public_base_url'],
]);
$checks['r2_write_credentials'] = ['ok' => R2Storage::readyForWrite(), 'detail' => R2Storage::readyForWrite() ? 'configured' : 'pending_private_credentials'];

$result = [
    'ok' => $failed === [],
    'failed' => $failed,
    'checks' => $checks,
    'time' => gmdate(DATE_ATOM),
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
exit($failed === [] ? 0 : 1);
