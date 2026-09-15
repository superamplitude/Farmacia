<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (PHP_SAPI !== 'cli') exit("CLI only\n");

$keys = [
    'R2_ACCESS_KEY_ID','R2_SECRET_ACCESS_KEY',
    'CLOUDFLARE_R2_ACCESS_KEY_ID','CLOUDFLARE_R2_SECRET_ACCESS_KEY',
    'AWS_ACCESS_KEY_ID','AWS_SECRET_ACCESS_KEY',
    'S3_ACCESS_KEY_ID','S3_SECRET_ACCESS_KEY',
    'CLOUDFLARE_API_TOKEN','R2_API_TOKEN'
];
$present=[];
foreach($keys as $key){
    $v=getenv($key);
    if($v!==false && trim((string)$v)!=='')$present[]=$key;
}
echo 'R2_ENV_PRESENT=' . ($present?implode(',', $present):'none') . "\n";
echo 'R2_READY=' . (R2Storage::readyForWrite()?'yes':'no') . "\n";
