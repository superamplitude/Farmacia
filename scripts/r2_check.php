<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (PHP_SAPI !== 'cli') exit("CLI only\n");

if (!class_exists('R2Storage')) {
    fwrite(STDERR, "R2_CHECK_FAIL storage client unavailable\n");
    exit(2);
}

$config = R2Storage::config();
echo "R2_ACCOUNT=" . ($config['account_id'] ?: 'missing') . "\n";
echo "R2_BUCKET=" . ($config['bucket'] ?: 'missing') . "\n";
echo "R2_PUBLIC=" . ($config['public_base_url'] ?: 'missing') . "\n";

if (!R2Storage::readyForWrite()) {
    fwrite(STDERR, "R2_CHECK_SKIPPED write credentials not configured in private .env\n");
    exit(3);
}

$key = 'health/r2-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
$payload = json_encode([
    'ok' => true,
    'service' => 'farmacia-superamplitude',
    'timestamp' => gmdate(DATE_ATOM),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

try {
    $url = R2Storage::put($key, (string)$payload, 'application/json');
    echo "R2_CHECK_OK url=" . $url . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "R2_CHECK_FAIL " . $e->getMessage() . "\n");
    exit(4);
}
