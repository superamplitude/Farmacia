<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
Auth::require('super_admin');

$totals = [
    'pharmacies' => (int)$db->query('SELECT COUNT(*) FROM pharmacies')->fetchColumn(),
    'medications' => (int)$db->query('SELECT COUNT(*) FROM medications')->fetchColumn(),
    'products' => (int)$db->query('SELECT COUNT(*) FROM pharmacy_products')->fetchColumn(),
    'users' => (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'orders' => (int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    'images' => (int)$db->query("SELECT COUNT(*) FROM medications WHERE COALESCE(image_url,'')<>''")->fetchColumn(),
];
$pharmacies = $db->query("SELECT p.*, (SELECT COUNT(*) FROM pharmacy_products pp WHERE pp.pharmacy_id=p.id) products, (SELECT COUNT(*) FROM orders o WHERE o.pharmacy_id=p.id) orders, (SELECT COUNT(*) FROM users u WHERE u.pharmacy_id=p.id) users FROM pharmacies p ORDER BY p.id")->fetchAll();
$r2 = R2Storage::config();
$r2State = R2Storage::credentialState();
$aiEnabled = (string)env('AI_ENABLED','0') === '1';
$payment = PaymentGateway::provider();
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="<?=h(url('assets/app.css'))?>"><title>Super Admin · Farmácia SuperAmplitude</title></head><body>
<header><div class="wrap top"><div><strong>Super Admin</strong><small>Farmácia SuperAmplitude · controle global</small></div><nav><a href="<?=h(url())?>">Loja</a><a href="<?=h(url('admin.php'))?>">Gestão completa</a><a href="<?=h(url('farmacia-admin.php'))?>">Admin da farmácia</a><a href="<?=h(url('admin.php?logout=1'))?>">Sair</a></nav></div></header>
<main class="wrap">
<section class="hero"><div><span class="eyebrow">Super Administração</span><h1>Visão global da plataforma</h1><p>Farmácias, catálogo, contas, pedidos, imagens e integrações em uma visão operacional.</p></div></section>
<div class="stats"><div><b><?=$totals['pharmacies']?></b><span>Farmácias</span></div><div><b><?=$totals['medications']?></b><span>Medicamentos ANVISA</span></div><div><b><?=$totals['products']?></b><span>Produtos materializados</span></div><div><b><?=$totals['orders']?></b><span>Pedidos</span></div></div>
<section class="panel"><h2>Integridade e integrações</h2><div class="staffgrid"><div><b>R2 / imagens</b><small><?=h($r2State)?> · <?=h($r2['public_base_url'])?></small></div><div><b>IA</b><small><?=$aiEnabled?'provedor habilitado':'fallback seguro'?></small></div><div><b>Pagamento</b><small><?=h($payment)?><?=PaymentGateway::mercadoPagoReady()?' · online pronto':' · online sem credencial'?></small></div><div><b>Contas</b><small><?=$totals['users']?> usuários cadastrados</small></div><div><b>Imagens vinculadas</b><small><?=$totals['images']?> de <?=$totals['medications']?></small></div></div></section>
<section class="panel"><h2>Farmácias</h2><div class="store-list"><?php foreach($pharmacies as $p):?><a class="store-pill" href="<?=h(url('admin.php?pharmacy='.(int)$p['id']))?>"><b><?=h($p['name'])?></b><small><?=h($p['slug'])?> · produtos <?=$p['products']?> · usuários <?=$p['users']?> · pedidos <?=$p['orders']?></small></a><?php endforeach;?></div><p><a class="ghost" href="<?=h(url('admin.php'))?>">Abrir gestão completa e criar farmácia</a></p></section>
</main></body></html>
