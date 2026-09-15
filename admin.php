<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

$msg = '';
$error = '';

if (isset($_GET['logout'])) {
    Auth::logout();
    header('Location: ' . url('admin.php'));
    exit;
}

if (!Auth::check()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (Auth::login($db, trim((string)($_POST['email'] ?? '')), (string)($_POST['password'] ?? ''))) {
            header('Location: ' . url('admin.php'));
            exit;
        }
        $msg = 'Login inválido.';
    }
    ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="<?=h(url('assets/app.css'))?>"><title>Painel Farmácia</title></head><body><main class="wrap narrow"><div class="loginbrand"><h1>Painel Farmácia</h1><p>Super Admin · Farmácia · Funcionários</p></div><?php if($msg):?><div class="flash"><?=h($msg)?></div><?php endif;?><form method="post" class="panel login"><input type="hidden" name="_csrf" value="<?=h(csrf_token())?>"><input type="email" name="email" placeholder="E-mail" required><input type="password" name="password" placeholder="Senha" required><button>Entrar</button></form></main></body></html><?php
    exit;
}

$role = Auth::role();
$pid = Auth::pharmacyId();
if ($role === 'super_admin' && isset($_GET['pharmacy'])) $pid = (int)$_GET['pharmacy'];
if (!$pid) {
    $p = Pharmacy::ensureDefault($db);
    $pid = (int)$p['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'pharmacy_create' && Auth::can('super_admin')) {
            $name = trim((string)($_POST['name'] ?? ''));
            $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower(trim((string)($_POST['slug'] ?? ''))));
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            if ($name === '' || $slug === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10) {
                throw new RuntimeException('Informe nome, slug, e-mail válido e senha inicial com pelo menos 10 caracteres.');
            }
            $db->beginTransaction();
            $st = $db->prepare('INSERT INTO pharmacies(name,slug,email,phone,city,state,status) VALUES(?,?,?,?,?,?,?)');
            $st->execute([$name,$slug,$email,trim((string)($_POST['phone']??'')),trim((string)($_POST['city']??'')),strtoupper(trim((string)($_POST['state']??''))),'active']);
            $newPid = (int)$db->lastInsertId();
            $u = $db->prepare('INSERT INTO users(pharmacy_id,email,password_hash,name,role) VALUES(?,?,?,?,?)');
            $u->execute([$newPid,$email,password_hash($password,PASSWORD_DEFAULT),$name.' Admin','pharmacy_admin']);
            $db->commit();
            $msg = 'Farmácia/cliente criada com administrador próprio.';
        }

        if ($action === 'pharmacy_update' && Auth::can('pharmacy_admin')) {
            $st = $db->prepare('UPDATE pharmacies SET name=?,cnpj=?,cnes=?,afe=?,responsible_pharmacist=?,crf=?,phone=?,email=?,address=?,city=?,state=?,zip=?,delivery_enabled=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $st->execute([
                trim((string)($_POST['name']??'')),trim((string)($_POST['cnpj']??'')),trim((string)($_POST['cnes']??'')),trim((string)($_POST['afe']??'')),
                trim((string)($_POST['responsible_pharmacist']??'')),trim((string)($_POST['crf']??'')),trim((string)($_POST['phone']??'')),trim((string)($_POST['email']??'')),
                trim((string)($_POST['address']??'')),trim((string)($_POST['city']??'')),strtoupper(trim((string)($_POST['state']??''))),trim((string)($_POST['zip']??'')),isset($_POST['delivery_enabled'])?1:0,$pid
            ]);
            Catalog::audit($db,'pharmacy.update','pharmacy',(string)$pid);
            $msg = 'Dados da farmácia atualizados.';
        }

        if ($action === 'staff_create' && Auth::can('pharmacy_admin')) {
            $r = (string)($_POST['role'] ?? 'attendant');
            $allowed = ['pharmacy_admin','pharmacist','attendant','stock','delivery'];
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            if (!in_array($r,$allowed,true) || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<10) {
                throw new RuntimeException('Dados do funcionário inválidos ou senha muito curta.');
            }
            $st = $db->prepare('INSERT INTO users(pharmacy_id,email,password_hash,name,role) VALUES(?,?,?,?,?)');
            $st->execute([$pid,$email,password_hash($password,PASSWORD_DEFAULT),trim((string)($_POST['name']??'')),$r]);
            $msg = 'Funcionário criado.';
        }

        if ($action === 'staff_toggle' && Auth::can('pharmacy_admin')) {
            $uid = (int)($_POST['id'] ?? 0);
            if ($uid === Auth::userId()) throw new RuntimeException('Você não pode desativar seu próprio acesso nesta tela.');
            $st = $db->prepare('UPDATE users SET active=CASE active WHEN 1 THEN 0 ELSE 1 END WHERE id=? AND pharmacy_id=?');
            $st->execute([$uid,$pid]);
            $msg = 'Status do funcionário atualizado.';
        }

        if ($action === 'product_upsert' && Auth::can('pharmacy_admin','stock','pharmacist')) {
            $mid = (int)($_POST['medication_id'] ?? 0);
            $price = max(0,(float)($_POST['price'] ?? 0));
            $stock = max(0,(int)($_POST['stock'] ?? 0));
            $active = isset($_POST['active']) ? 1 : 0;
            if ($active && ($price <= 0 || $stock <= 0)) throw new RuntimeException('Para publicar um produto, informe preço e estoque maiores que zero.');
            $img = trim((string)($_POST['image_url'] ?? ''));

            if (!empty($_FILES['image_file']['tmp_name']) && is_uploaded_file($_FILES['image_file']['tmp_name'])) {
                $f = $_FILES['image_file'];
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
                $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                if (!isset($allowed[$mime]) || (int)$f['size'] > 5*1024*1024) throw new RuntimeException('Imagem inválida. Use JPG, PNG ou WEBP de até 5 MB.');
                if (!R2Storage::readyForWrite()) throw new RuntimeException('R2 de escrita ainda não possui credenciais privadas. Informe uma URL ou configure o R2.');
                $bytes = file_get_contents($f['tmp_name']);
                if ($bytes === false) throw new RuntimeException('Não foi possível ler a imagem enviada.');
                $key = 'farmacia/produtos/' . $mid . '-' . substr(hash('sha256',$bytes),0,16) . '.' . $allowed[$mime];
                $img = R2Storage::put($key,$bytes,$mime);
            }

            $st = $db->prepare('INSERT INTO pharmacy_products(pharmacy_id,medication_id,price,stock,active,image_url) VALUES(?,?,?,?,?,?) ON CONFLICT(pharmacy_id,medication_id) DO UPDATE SET price=excluded.price,stock=excluded.stock,active=excluded.active,image_url=excluded.image_url,updated_at=CURRENT_TIMESTAMP');
            $st->execute([$pid,$mid,$price,$stock,$active,$img]);
            Catalog::audit($db,'product.upsert','medication',(string)$mid,['price'=>$price,'stock'=>$stock,'active'=>$active,'image'=>(bool)$img]);
            $msg = 'Produto atualizado.';
        }

        if ($action === 'zone_create' && Auth::can('pharmacy_admin','delivery')) {
            $st = $db->prepare('INSERT INTO delivery_zones(pharmacy_id,name,zip_prefix,city,fee,free_above,eta_min,eta_max) VALUES(?,?,?,?,?,?,?,?)');
            $st->execute([$pid,trim((string)($_POST['name']??'')),trim((string)($_POST['zip_prefix']??'')),trim((string)($_POST['city']??'')),(float)($_POST['fee']??0),($_POST['free_above']??'')!==''?(float)$_POST['free_above']:null,($_POST['eta_min']??'')!==''?(int)$_POST['eta_min']:null,($_POST['eta_max']??'')!==''?(int)$_POST['eta_max']:null]);
            $msg = 'Zona de entrega criada.';
        }

        if ($action === 'rx_review' && Auth::can('pharmacist','pharmacy_admin')) {
            $id = (int)($_POST['id'] ?? 0);
            $status = in_array($_POST['status']??'', ['approved','rejected'], true) ? (string)$_POST['status'] : 'rejected';
            $notes = trim((string)($_POST['notes']??''));
            $st = $db->prepare('UPDATE prescriptions SET status=?,pharmacist_id=?,pharmacist_notes=?,validated_at=CURRENT_TIMESTAMP WHERE id=? AND pharmacy_id=?');
            $st->execute([$status,Auth::userId(),$notes,$id,$pid]);
            $o = $db->prepare("UPDATE orders SET pharmacist_status=?,status=?,delivery_status=?,updated_at=CURRENT_TIMESTAMP WHERE id=(SELECT order_id FROM prescriptions WHERE id=?) AND pharmacy_id=?");
            $o->execute([$status,$status==='approved'?'approved':'rejected',$status==='approved'?'pending':'blocked_rejected',$id,$pid]);
            Catalog::audit($db,'prescription.review','prescription',(string)$id,['status'=>$status]);
            $msg = 'Receita avaliada.';
        }

        if ($action === 'order_update' && Auth::can('pharmacy_admin','pharmacist','attendant','delivery')) {
            $id = (int)($_POST['id'] ?? 0);
            $status = (string)($_POST['status'] ?? 'pending');
            $delivery = (string)($_POST['delivery_status'] ?? 'pending');
            $allowed = ['pending','approved','rejected','separating','ready','delivered','cancelled'];
            $dallowed = ['pending','blocked_pharmacist','blocked_rejected','separating','ready','out_for_delivery','delivered','failed','pickup_ready'];
            if (!in_array($status,$allowed,true) || !in_array($delivery,$dallowed,true)) throw new RuntimeException('Status inválido.');
            $current = $db->prepare('SELECT payment_status FROM orders WHERE id=? AND pharmacy_id=?');
            $current->execute([$id,$pid]);
            $row = $current->fetch();
            if (!$row) throw new RuntimeException('Pedido não encontrado.');
            $payment = (string)$row['payment_status'];
            if (Auth::can('pharmacy_admin')) {
                $candidate = (string)($_POST['payment_status'] ?? $payment);
                if (in_array($candidate,['pending','paid','failed','refunded'],true)) $payment = $candidate;
            }
            $st = $db->prepare('UPDATE orders SET status=?,delivery_status=?,payment_status=?,paid_at=CASE WHEN ?="paid" AND paid_at IS NULL THEN CURRENT_TIMESTAMP ELSE paid_at END,updated_at=CURRENT_TIMESTAMP WHERE id=? AND pharmacy_id=?');
            $st->execute([$status,$delivery,$payment,$payment,$id,$pid]);
            Catalog::audit($db,'order.update','order',(string)$id,['status'=>$status,'delivery'=>$delivery,'payment'=>$payment]);
            $msg = 'Pedido atualizado.';
        }

        if ($action === 'payment_sync' && Auth::can('pharmacy_admin','attendant')) {
            $id = (int)($_POST['id'] ?? 0);
            $st = $db->prepare('SELECT payment_external_id FROM orders WHERE id=? AND pharmacy_id=?');
            $st->execute([$id,$pid]);
            $external = (string)($st->fetchColumn() ?: '');
            if ($external === '') throw new RuntimeException('Pedido não possui pagamento online para sincronizar.');
            PaymentGateway::syncMercadoPago($db,$external);
            $msg = 'Pagamento sincronizado com o gateway.';
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

if (($_GET['action'] ?? '') === 'prescription') {
    Auth::require('pharmacist','pharmacy_admin');
    $id = (int)($_GET['id'] ?? 0);
    $st = $db->prepare('SELECT file_path,mime_type,original_name FROM prescriptions WHERE id=? AND pharmacy_id=?');
    $st->execute([$id,$pid]);
    $r = $st->fetch();
    if (!$r || !is_file($r['file_path'])) { http_response_code(404); exit('Arquivo não encontrado'); }
    header('Content-Type: ' . $r['mime_type']);
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/','_',basename($r['original_name'] ?: 'receita')) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($r['file_path']);
    exit;
}

$pharmacies = $role === 'super_admin' ? $db->query('SELECT * FROM pharmacies ORDER BY id DESC')->fetchAll() : [];
$st = $db->prepare('SELECT * FROM pharmacies WHERE id=?'); $st->execute([$pid]); $pharmacy = $st->fetch();
$staff = [];
if (Auth::can('pharmacy_admin')) { $st=$db->prepare('SELECT id,name,email,role,active FROM users WHERE pharmacy_id=? ORDER BY name'); $st->execute([$pid]); $staff=$st->fetchAll(); }
$ordersSt=$db->prepare('SELECT * FROM orders WHERE pharmacy_id=? ORDER BY id DESC LIMIT 60'); $ordersSt->execute([$pid]); $orders=$ordersSt->fetchAll();
$rxSt=$db->prepare("SELECT p.*,o.customer_name,o.id order_number FROM prescriptions p JOIN orders o ON o.id=p.order_id WHERE p.pharmacy_id=? ORDER BY p.id DESC LIMIT 40"); $rxSt->execute([$pid]); $prescriptions=$rxSt->fetchAll();
$zonesSt=$db->prepare('SELECT * FROM delivery_zones WHERE pharmacy_id=? ORDER BY id DESC'); $zonesSt->execute([$pid]); $zones=$zonesSt->fetchAll();
$search=trim((string)($_GET['q']??'')); $meds=$search!==''?Catalog::search($db,$search,30):[];
$counts=[];
foreach(['medications'=>'SELECT COUNT(*) FROM medications','products'=>'SELECT COUNT(*) FROM pharmacy_products WHERE pharmacy_id='.$pid,'orders'=>'SELECT COUNT(*) FROM orders WHERE pharmacy_id='.$pid,'rx'=>"SELECT COUNT(*) FROM prescriptions WHERE pharmacy_id=$pid AND status='awaiting_review'"] as $k=>$sql) $counts[$k]=(int)$db->query($sql)->fetchColumn();
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="<?=h(url('assets/app.css'))?>"><title>Painel · <?=h($pharmacy['name']??'Farmácia')?></title></head><body>
<header><div class="wrap top"><div><strong>Painel Farmácia</strong><small><?=h($_SESSION['name'])?> · <?=h($role)?></small></div><nav><a href="<?=h(url())?>">Loja</a><a href="#pedidos">Pedidos</a><?php if(Auth::can('pharmacist','pharmacy_admin')):?><a href="#receitas">Receitas</a><?php endif;?><?php if(Auth::can('pharmacy_admin')):?><a href="#equipe">Equipe</a><?php endif;?><a href="?logout=1">Sair</a></nav></div></header>
<main class="wrap">
<?php if($msg):?><div class="flash"><?=h($msg)?></div><?php endif;?><?php if($error):?><div class="flash"><?=h($error)?></div><?php endif;?>

<?php if($role==='super_admin'):?><section class="panel"><div class="section-title"><h2>Super Admin · Farmácias</h2></div><div class="store-list"><?php foreach($pharmacies as $p):?><a class="store-pill" href="?pharmacy=<?=$p['id']?>"><b><?=h($p['name'])?></b><small><?=h($p['slug'])?> · <?=h($p['status'])?></small></a><?php endforeach;?></div><details><summary>Adicionar farmácia/cliente</summary><form method="post" class="formgrid"><input type="hidden" name="_csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="pharmacy_create"><input name="name" placeholder="Nome da farmácia" required><input name="slug" placeholder="slug-da-loja" required><input type="email" name="email" placeholder="E-mail do administrador" required><input name="phone" placeholder="Telefone"><input name="city" placeholder="Cidade"><input name="state" placeholder="UF"><input type="password" name="password" minlength="10" placeholder="Senha inicial" required><button>Criar cliente</button></form></details></section><?php endif;?>

<div class="stats"><div><b><?=$counts['medications']?></b><span>Base Anvisa</span></div><div><b><?=$counts['products']?></b><span>Produtos da loja</span></div><div><b><?=$counts['orders']?></b><span>Pedidos</span></div><div><b><?=$counts['rx']?></b><span>Receitas pendentes</span></div></div>

<?php if(Auth::can('pharmacy_admin')):?><section class="panel"><h2>Cadastro da farmácia</h2><form method="post" class="formgrid"><input type="hidden" name="_csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="pharmacy_update"><input name="name" value="<?=h($pharmacy['name']??'')?>" placeholder="Nome" required><input name="cnpj" value="<?=h($pharmacy['cnpj']??'')?>" placeholder="CNPJ"><input name="cnes" value="<?=h($pharmacy['cnes']??'')?>" placeholder="CNES"><input name="afe" value="<?=h($pharmacy['afe']??'')?>" placeholder="AFE"><input name="responsible_pharmacist" value="<?=h($pharmacy['responsible_pharmacist']??'')?>" placeholder="Farmacêutico responsável"><input name="crf" value="<?=h($pharmacy['crf']??'')?>" placeholder="CRF"><input name="phone" value="<?=h($pharmacy['phone']??'')?>" placeholder="Telefone"><input type="email" name="email" value="<?=h($pharmacy['email']??'')?>" placeholder="E-mail"><input name="address" value="<?=h($pharmacy['address']??'')?>" placeholder="Endereço"><input name="city" value="<?=h($pharmacy['city']??'')?>" placeholder="Cidade"><input name="state" value="<?=h($pharmacy['state']??'')?>" placeholder="UF"><input name="zip" value="<?=h($pharmacy['zip']??'')?>" placeholder="CEP"><label><input type="checkbox" name="delivery_enabled" <?=((int)($pharmacy['delivery_enabled']??0)===1)?'checked':''?>> Delivery habilitado</label><button>Salvar cadastro</button></form></section><?php endif;?>

<?php if(Auth::can('pharmacy_admin','stock','pharmacist')):?><section class="panel"><h2>Catálogo, preço, estoque e imagens</h2><form method="get" class="inline"><input type="hidden" name="pharmacy" value="<?=$pid?>"><input name="q" value="<?=h($search)?>" placeholder="Nome, princípio ativo, laboratório ou registro"><button>Pesquisar base Anvisa</button></form><?php foreach($meds as $m): $current=Catalog::storeProduct($db,$pid,(int)$m['id']);?><form method="post" enctype="multipart/form-data" class="adminrow"><input type="hidden" name="_csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="product_upsert"><input type="hidden" name="medication_id" value="<?=$m['id']?>"><div><b><?=h($m['product_name'])?></b><small><?=h($m['active_ingredient'])?> · Reg. <?=h($m['registration'])?><?php if($m['controlled']):?> · CONTROLADO<?php endif;?></small></div><input name="price" type="number" min="0" step="0.01" value="<?=h($current['store_price']??'')?>" placeholder="Preço" required><input name="stock" type="number" min="0" value="<?=h($current['store_stock']??0)?>" placeholder="Estoque" required><input name="image_url" value="<?=h($current['store_image_url']??'')?>" placeholder="URL da imagem"><input type="file" name="image_file" accept="image/jpeg,image/png,image/webp"><label><input type="checkbox" name="active" <?=($current&&(int)$current['store_active']===1)?'checked':''?>> Ativo</label><button>Salvar</button></form><?php endforeach;?></section><?php endif;?>

<section id="pedidos" class="panel"><h2>Pedidos, pagamento e delivery</h2><?php if(!$orders):?><p>Nenhum pedido ainda.</p><?php endif;?><?php foreach($orders as $o):?><div class="orderrow"><div><b>#<?=$o['id']?> · <?=h($o['customer_name'])?></b><small><?=h($o['delivery_type'])?> · <?=h($o['delivery_city'])?> · R$ <?=number_format((float)$o['total'],2,',','.')?> · Pagamento: <?=h($o['payment_method'])?> / <?=h($o['payment_status'])?> · Farmacêutico: <?=h($o['pharmacist_status'])?></small><?php if(!empty($o['public_token'])):?><a target="_blank" href="<?=h(url('pedido.php?t='.rawurlencode((string)$o['public_token'])))?>">Acompanhamento público</a><?php endif;?></div><form method="post" class="inline"><input type="hidden" name="_csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="order_update"><input type="hidden" name="id" value="<?=$o['id']?>"><select name="status"><?php foreach(['pending','approved','rejected','separating','ready','delivered','cancelled'] as $s):?><option value="<?=$s?>" <?=$o['status']===$s?'selected':''?>><?=$s?></option><?php endforeach;?></select><select name="delivery_status"><?php foreach(['pending','blocked_pharmacist','blocked_rejected','separating','ready','out_for_delivery','delivered','failed','pickup_ready'] as $s):?><option value="<?=$s?>" <?=$o['delivery_status']===$s?'selected':''?>><?=$s?></option><?php endforeach;?></select><?php if(Auth::can('pharmacy_admin')):?><select name="payment_status"><?php foreach(['pending','paid','failed','refunded'] as $s):?><option value="<?=$s?>" <?=$o['payment_status']===$s?'selected':''?>>pagamento: <?=$s?></option><?php endforeach;?></select><?php endif;?><button>Atualizar</button></form><?php if(!empty($o['payment_external_id'])):?><form method="post" class="inline"><input type="hidden" name="_csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="payment_sync"><input type="hidden" name="id" value="<?=$o['id']?>"><button class="ghost">Sincronizar gateway</button></form><?php endif;?></div><?php endforeach;?></section>

<?php if(Auth::can('pharmacist','pharmacy_admin')):?><section id="receitas" class="panel"><h2>Avaliação de receitas</h2><?php if(!$prescriptions):?><p>Nenhuma receita recebida.</p><?php endif;?><?php foreach($prescriptions as $r):?><div class="rxrow"><div><b>Pedido #<?=$r['order_number']?> · <?=h($r['customer_name'])?></b><small>Status: <?=h($r['status'])?> · <?=h($r['created_at'])?></small><a target="_blank" href="?action=prescription&id=<?=$r['id']?>&pharmacy=<?=$pid?>">Abrir receita</a></div><?php if($r['status']==='awaiting_review'):?><form method="post" class="inline"><input type="hidden" name="_csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="rx_review"><input type="hidden" name="id" value="<?=$r['id']?>"><input name="notes" placeholder="Observação farmacêutica"><select name="status"><option value="approved">Aprovar</option><option value="rejected">Rejeitar</option></select><button>Registrar avaliação</button></form><?php endif;?></div><?php endforeach;?></section><?php endif;?>

<?php if(Auth::can('pharmacy_admin')):?><section id="equipe" class="panel"><h2>Equipe e permissões</h2><div class="staffgrid"><?php foreach($staff as $u):?><div><b><?=h($u['name']?:$u['email'])?></b><small><?=h($u['role'])?> · <?=((int)$u['active'])?'ativo':'inativo'?></small><form method="post"><input type="hidden" name="_csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="staff_toggle"><input type="hidden" name="id" value="<?=$u['id']?>"><button class="ghost"><?=((int)$u['active'])?'Desativar':'Ativar'?></button></form></div><?php endforeach;?></div><details><summary>Criar acesso de funcionário</summary><form method="post" class="formgrid"><input type="hidden" name="_csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="staff_create"><input name="name" placeholder="Nome" required><input type="email" name="email" placeholder="E-mail" required><input type="password" name="password" minlength="10" placeholder="Senha inicial" required><select name="role"><option value="pharmacist">Farmacêutico</option><option value="attendant">Atendimento</option><option value="stock">Estoque</option><option value="delivery">Delivery</option><option value="pharmacy_admin">Administrador</option></select><button>Criar funcionário</button></form></details></section><?php endif;?>

<?php if(Auth::can('pharmacy_admin','delivery')):?><section class="panel"><h2>Zonas de entrega</h2><div class="staffgrid"><?php foreach($zones as $z):?><div><b><?=h($z['name'])?></b><small><?=h($z['city'])?> · CEP <?=h($z['zip_prefix'])?> · R$ <?=number_format((float)$z['fee'],2,',','.')?><?php if($z['eta_min']):?> · <?=$z['eta_min']?>–<?=$z['eta_max']?> min<?php endif;?></small></div><?php endforeach;?></div><details><summary>Adicionar zona</summary><form method="post" class="formgrid"><input type="hidden" name="_csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="zone_create"><input name="name" placeholder="Nome da zona" required><input name="zip_prefix" placeholder="Prefixo CEP"><input name="city" placeholder="Cidade"><input name="fee" type="number" step="0.01" min="0" placeholder="Taxa" required><input name="free_above" type="number" step="0.01" min="0" placeholder="Grátis acima de"><input name="eta_min" type="number" min="0" placeholder="ETA mín."><input name="eta_max" type="number" min="0" placeholder="ETA máx."><button>Adicionar zona</button></form></details></section><?php endif;?>

<section class="panel"><h2>Integrações</h2><div class="staffgrid"><div><b>Cloudflare R2</b><small><?=R2Storage::readyForWrite()?'escrita configurada':'leitura pública pronta; credenciais privadas de escrita pendentes'?></small></div><div><b>IA</b><small><?=((string)env('AI_ENABLED','0')==='1')?'provedor habilitado':'fallback seguro ativo; provedor generativo desabilitado'?></small></div><div><b>Pagamentos</b><small>Provider: <?=h(PaymentGateway::provider())?> · PIX online: <?=PaymentGateway::mercadoPagoReady()?'ativo':'aguardando credencial do gateway'?></small></div><div><b>SNCR</b><small><?=((string)env('SNCR_ENABLED','0')==='1')?'habilitado':'desabilitado até integração oficial aplicável'?></small></div></div></section>
</main></body></html>
