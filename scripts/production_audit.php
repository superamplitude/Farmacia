<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
if (PHP_SAPI !== 'cli') exit("CLI only\n");

$checks = [];
$fail = [];
$add = static function(string $name, bool $ok, mixed $detail=null) use (&$checks,&$fail): void {
    $checks[$name] = ['ok'=>$ok,'detail'=>$detail];
    if (!$ok) $fail[] = $name;
};

$pharmacy = Pharmacy::ensureDefault($db);
$pid = (int)$pharmacy['id'];
$meds = (int)$db->query('SELECT COUNT(*) FROM medications')->fetchColumn();
$st = $db->prepare('SELECT COUNT(*) FROM pharmacy_products WHERE pharmacy_id=?');
$st->execute([$pid]);
$products = (int)$st->fetchColumn();
$adminSt = $db->prepare("SELECT COUNT(*) FROM users WHERE pharmacy_id=? AND role='pharmacy_admin' AND active=1");
$adminSt->execute([$pid]);
$storeAdmins = (int)$adminSt->fetchColumn();
$superAdmins = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='super_admin' AND active=1")->fetchColumn();
$images = (int)$db->query("SELECT COUNT(*) FROM medications WHERE COALESCE(image_url,'')<>''")->fetchColumn();

$chatJs = @file_get_contents(dirname(__DIR__) . '/assets/chat.js') ?: '';
$css = @file_get_contents(dirname(__DIR__) . '/assets/app.css') ?: '';

$add('catalog_loaded', $meds > 0, $meds);
$add('store_catalog_materialized', $meds > 0 && $products >= $meds, ['medications'=>$meds,'products'=>$products]);
$add('super_admin_account', $superAdmins >= 1, $superAdmins);
$add('pharmacy_admin_account', $storeAdmins >= 1, $storeAdmins);
$add('super_admin_panel', is_file(dirname(__DIR__) . '/superadmin.php'), 'superadmin.php');
$add('pharmacy_admin_panel', is_file(dirname(__DIR__) . '/farmacia-admin.php'), 'farmacia-admin.php');
$add('panel_router', is_file(dirname(__DIR__) . '/painel.php'), 'painel.php');
$add('chat_close_logic', str_contains($chatJs, "setOpen(false)") && str_contains($chatJs, "Escape"), 'assets/chat.js');
$add('chat_hidden_css', str_contains($css, '.chat[hidden]') && str_contains($css, 'display:none!important'), 'assets/app.css');
$add('r2_public_contract', R2Storage::config()['public_base_url'] === 'https://img.farmacia.superamplitude.com', R2Storage::config()['public_base_url']);
$add('image_pipeline', is_file(dirname(__DIR__) . '/scripts/collect_product_images.php') && is_file(dirname(__DIR__) . '/scripts/sync_images.php'), ['linked'=>$images,'r2_state'=>R2Storage::credentialState()]);
$add('private_env', is_file(private_state_dir() . '/.env') && is_readable(private_state_dir() . '/.env'), private_state_dir() . '/.env');

$result = [
    'ok' => $fail === [],
    'failed' => $fail,
    'checks' => $checks,
    'time' => gmdate(DATE_ATOM),
];

echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";
exit($fail === [] ? 0 : 1);
