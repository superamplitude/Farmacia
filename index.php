<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

if (isset($_GET['health'])) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'app'=>'farmacia','time'=>date(DATE_ATOM)]); exit; }

$_SESSION['cart'] ??= [];
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $id = (int)($_POST['id'] ?? 0); $qty = max(1, min(20, (int)($_POST['qty'] ?? 1)));
        $st = $db->prepare('SELECT * FROM medications WHERE id=?'); $st->execute([$id]); $m=$st->fetch();
        if ($m && (int)$m['sell_online'] === 1 && (int)$m['controlled'] === 0) { $_SESSION['cart'][$id] = ($_SESSION['cart'][$id] ?? 0) + $qty; $flash='Adicionado ao carrinho.'; }
        else $flash='Este item não está liberado para venda remota.';
    }
    if ($action === 'clear') { $_SESSION['cart'] = []; $flash='Carrinho limpo.'; }
    if ($action === 'checkout') {
        $name=trim($_POST['name']??''); $email=trim($_POST['email']??''); $phone=trim($_POST['phone']??'');
        if ($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL) || empty($_SESSION['cart'])) $flash='Preencha os dados obrigatórios e mantenha itens no carrinho.';
        else {
            $items=[]; $total=0.0; $needsRx=false; $blocked=false;
            foreach ($_SESSION['cart'] as $id=>$qty) {
                $st=$db->prepare('SELECT * FROM medications WHERE id=?'); $st->execute([(int)$id]); $m=$st->fetch(); if(!$m) continue;
                if((int)$m['controlled']===1 || (int)$m['sell_online']!==1) {$blocked=true; break;}
                $price=(float)($m['price'] ?: $m['pmc'] ?: 0); $total += $price*(int)$qty; $needsRx = $needsRx || (int)$m['requires_prescription']===1;
                $items[] = [$m,(int)$qty,$price];
            }
            if($blocked) $flash='O pedido contém item que não pode ser comercializado remotamente.';
            else {
                $rxPath=null;
                if(!empty($_FILES['prescription']['tmp_name'])) {
                    $ext=strtolower(pathinfo($_FILES['prescription']['name'],PATHINFO_EXTENSION));
                    if(in_array($ext,['pdf','jpg','jpeg','png'],true) && $_FILES['prescription']['size'] <= 8*1024*1024) {
                        $dir='/home/superamplitude/.farmacia/uploads'; if(!is_dir($dir)) mkdir($dir,0770,true);
                        $rxPath=$dir.'/rx_'.date('YmdHis').'_'.bin2hex(random_bytes(6)).'.'.$ext; move_uploaded_file($_FILES['prescription']['tmp_name'],$rxPath);
                    }
                }
                if($needsRx && !$rxPath) $flash='Este pedido exige receita. Envie PDF/JPG/PNG para análise do farmacêutico.';
                else {
                    $db->beginTransaction();
                    $st=$db->prepare('INSERT INTO orders(customer_name,customer_email,customer_phone,status,pharmacist_status,prescription_path,total) VALUES(?,?,?,?,?,?,?)');
                    $st->execute([$name,$email,$phone,'pending',$needsRx?'awaiting_review':'not_required',$rxPath,$total]); $oid=(int)$db->lastInsertId();
                    $it=$db->prepare('INSERT INTO order_items(order_id,medication_id,quantity,unit_price) VALUES(?,?,?,?)'); foreach($items as [$m,$qty,$price]) $it->execute([$oid,$m['id'],$qty,$price]);
                    $db->commit(); Catalog::audit($db,'order.created','order',(string)$oid,['total'=>$total,'rx'=>$needsRx]); $_SESSION['cart']=[]; $flash='Pedido #'.$oid.' registrado. '.($needsRx?'Aguardando validação farmacêutica.':'Aguardando processamento.');
                }
            }
        }
    }
}
$q=trim($_GET['q']??''); $meds=Catalog::search($db,$q,60);
$cartRows=[]; $cartTotal=0.0; foreach($_SESSION['cart'] as $id=>$qty){$st=$db->prepare('SELECT * FROM medications WHERE id=?');$st->execute([(int)$id]);if($m=$st->fetch()){$price=(float)($m['price']?:$m['pmc']?:0);$cartTotal+=$price*$qty;$cartRows[]=[$m,$qty,$price];}}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Farmácia SuperAmplitude</title><link rel="stylesheet" href="<?=h(url('assets/app.css'))?>"></head><body>
<header><div class="wrap top"><div><strong>Farmácia SuperAmplitude</strong><small>Catálogo inteligente com dados oficiais</small></div><nav><a href="<?=h(url())?>">Loja</a><a href="#carrinho">Carrinho (<?=count($_SESSION['cart'])?>)</a><a href="<?=h(url('admin.php'))?>">Admin</a></nav></div></header>
<main class="wrap"><section class="hero"><div><h1>Medicamentos, busca simples e controle responsável.</h1><p>Consulte por nome, princípio ativo, laboratório ou registro Anvisa.</p><form method="get"><input name="q" value="<?=h($q)?>" placeholder="Ex.: dipirona, paracetamol, laboratório..."><button>Buscar</button></form></div></section>
<?php if($flash):?><div class="flash"><?=h($flash)?></div><?php endif;?>
<section><div class="section-title"><h2>Catálogo</h2><span><?=count($meds)?> resultados exibidos</span></div><div class="grid">
<?php foreach($meds as $m):?><article class="card"><img src="<?=h($m['image_url'] ?: url('assets/medicine.svg'))?>" alt=""><div class="cardbody"><span class="tag"><?=h($m['regulatory_category'] ?: 'Cadastro Anvisa')?></span><h3><?=h($m['product_name'])?></h3><p><?=h($m['active_ingredient'])?></p><small><?=h($m['company'])?></small><div class="flags"><?php if((int)$m['controlled']):?><b>Controle especial</b><?php elseif((int)$m['requires_prescription']):?><b>Receita</b><?php elseif((int)$m['sell_online']):?><b>Venda remota liberada</b><?php else:?><b>Revisão necessária</b><?php endif;?></div><div class="price">R$ <?=number_format((float)($m['price']?:$m['pmc']?:0),2,',','.')?></div><form method="post"><input type="hidden" name="_csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="add"><input type="hidden" name="id" value="<?=$m['id']?>"><button <?=((int)$m['sell_online']!==1||(int)$m['controlled']===1)?'disabled':''?>>Adicionar</button></form></div></article><?php endforeach;?>
</div></section>
<section id="carrinho" class="cart"><div class="section-title"><h2>Carrinho</h2><strong>R$ <?=number_format($cartTotal,2,',','.')?></strong></div><?php if(!$cartRows):?><p>Seu carrinho está vazio.</p><?php else:?><ul><?php foreach($cartRows as [$m,$qty,$price]):?><li><span><?=h($m['product_name'])?> × <?=$qty?></span><b>R$ <?=number_format($price*$qty,2,',','.')?></b></li><?php endforeach;?></ul><form method="post" enctype="multipart/form-data" class="checkout"><input type="hidden" name="_csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="checkout"><input name="name" placeholder="Nome completo" required><input type="email" name="email" placeholder="E-mail" required><input name="phone" placeholder="Telefone"><label>Receita, quando exigida <input type="file" name="prescription" accept=".pdf,.jpg,.jpeg,.png"></label><button>Registrar pedido</button></form><form method="post"><input type="hidden" name="_csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="clear"><button class="ghost">Limpar carrinho</button></form><?php endif;?></section>
<p class="notice">Medicamentos sujeitos a controle especial não são vendidos remotamente. Pedidos sob prescrição só seguem após avaliação do farmacêutico responsável.</p></main><footer><div class="wrap">SuperAmplitude · Farmácia</div></footer></body></html>
