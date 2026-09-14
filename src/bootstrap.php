<?php
declare(strict_types=1);
define('APP_ROOT',dirname(__DIR__));
function load_env_file(string $file):void{if(!is_file($file))return;foreach(file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){$line=trim($line);if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;[$k,$v]=explode('=',$line,2);$k=trim($k);$v=trim($v," \t\n\r\0\x0B\"'");if($k!==''&&getenv($k)===false)putenv($k.'='.$v);}}
load_env_file('/home/superamplitude/.farmacia/.env');load_env_file(APP_ROOT.'/.env');
function env(string $key,mixed $default=null):mixed{$v=getenv($key);return $v===false?$default:$v;}
function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function url(string $path=''):string{$base=rtrim((string)env('APP_BASE','/Farmacia'),'/');return $base.($path!==''?'/'.ltrim($path,'/'):'');}
function csrf_token():string{if(empty($_SESSION['_csrf']))$_SESSION['_csrf']=bin2hex(random_bytes(24));return $_SESSION['_csrf'];}
function csrf_check():void{if(!hash_equals($_SESSION['_csrf']??'',$_POST['_csrf']??'')){http_response_code(419);exit('CSRF inválido');}}
function med_image(array $m):string{if(!empty($m['store_image_url']))return (string)$m['store_image_url'];if(!empty($m['image_url']))return (string)$m['image_url'];return url('assets/medicine.svg');}
if(session_status()!==PHP_SESSION_ACTIVE){session_name('farmacia_session');session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']),'samesite'=>'Lax','path'=>env('APP_BASE','/Farmacia')?:'/']);session_start();}
require APP_ROOT.'/src/Database.php';require APP_ROOT.'/src/Schema.php';require APP_ROOT.'/src/Auth.php';require APP_ROOT.'/src/Catalog.php';require APP_ROOT.'/src/Pharmacy.php';require APP_ROOT.'/src/AiAssistant.php';
$db=Database::connection();Schema::migrate($db);Auth::bootstrapAdmin($db);Pharmacy::ensureDefault($db);
