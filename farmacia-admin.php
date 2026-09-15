<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
Auth::require('pharmacy_admin','pharmacist','attendant','stock','delivery');

$pid = Auth::pharmacyId();
if (!$pid) $pid = (int)Pharmacy::ensureDefault($db)['id'];
$st = $db->prepare('SELECT * FROM pharmacies WHERE id=? LIMIT 1');
$st->execute([$pid]);
$pharmacy = $st->fetch();
if (!$pharmacy) { http_response_code(404); exit('Farmácia não encontrada'); }

$counts = [];
$queries = [
    'products' => 'SELECT COUNT(*) FROM pharmacy_products WHERE pharmacy_id=?',
    'active_products' => 'SELECT COUNT(*) FROM pharmacy_products WHERE pharmacy_id=? AND active=1 AND stock>0 AND price>0',
    'orders' => 'SELECT COUNT(*) FROM orders WHERE pharmacy_id=?',
    'rx' => "SELECT COUNT(*) FROM prescriptions WHERE pharmacy_id=? AND status='awaiting_review'",
    'staff' => 'SELECT COUNT(*) FROM users WHERE pharmacy_id=? AND active=1',
];
foreach ($queries as $key=>$sql) { $q=$db->prepare($sql); $q->execute([$pid]); $counts[$key]=(int)$q->fetchColumn(); }
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="<?=h(url('assets/app.css'))?>"><title>Admin · <?=h($pharmacy['name'])?></title></head><body>
<header><div class="wrap top"><div><strong>Admin da Farmácia</strong><small><?=h($pharmacy['name'])?> · <?=h(Auth::role())?></small></div><nav><a href="<?=h(url())?>">Loja</a><a href="<?=h(url('admin.php?pharmacy='.$pid))?>">Gestão completa</a><?php if(Auth::role()==='super_admin'):?><a href="<?=h(url('superadmin.php'))?>">Super Admin</a><?php endif;?><a href="<?=h(url('admin.php?logout=1'))?>">Sair</a></nav></div></header>
<main class="wrap">
<section class="hero"><div><span class="eyebrow">Operação da farmácia</span><h1><?=h($pharmacy['name'])?></h1><p>Produtos, estoque, pedidos, receitas, equipe e delivery vinculados a esta unidade.</p></div></section>
<div class="stats"><div><b><?=$counts['products']?></b><span>Produtos cadastrados</span></div><div><b><?=$counts['active_products']?></b><span>À venda online</span></div><div><b><?=$counts['orders']?></b><span>Pedidos</span></div><div><b><?=$counts['rx']?></b><span>Receitas pendentes</span></div></div>
<section class="panel"><h2>Acesso rápido</h2><div class="staffgrid"><a class="store-pill" href="<?=h(url('admin.php?pharmacy='.$pid))?>"><b>Catálogo e estoque</b><small>Preço, estoque, imagem e ativação dos produtos.</small></a><a class="store-pill" href="<?=h(url('admin.php?pharmacy='.$pid.'#pedidos'))?>"><b>Pedidos</b><small>Pagamento, separação, retirada e delivery.</small></a><a class="store-pill" href="<?=h(url('admin.php?pharmacy='.$pid.'#receitas'))?>"><b>Receitas</b><small>Avaliação farmacêutica e liberação.</small></a><a class="store-pill" href="<?=h(url('admin.php?pharmacy='.$pid.'#equipe'))?>"><b>Equipe</b><small><?=$counts['staff']?> acessos ativos nesta farmácia.</small></a></div></section>
<section class="panel"><h2>Cadastro da unidade</h2><p><b>CNPJ:</b> <?=h($pharmacy['cnpj']?:'não informado')?> · <b>CNES:</b> <?=h($pharmacy['cnes']?:'não informado')?> · <b>AFE:</b> <?=h($pharmacy['afe']?:'não informado')?></p><p><b>Farmacêutico responsável:</b> <?=h($pharmacy['responsible_pharmacist']?:'não informado')?> · <b>CRF:</b> <?=h($pharmacy['crf']?:'não informado')?></p><p><a href="<?=h(url('admin.php?pharmacy='.$pid))?>">Editar cadastro e configurações</a></p></section>
</main></body></html>
