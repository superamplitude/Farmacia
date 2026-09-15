<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

if (isset($_GET['health'])) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'app' => 'farmacia', 'time' => date(DATE_ATOM)]);
    exit;
}

$pharmacy = Pharmacy::current($db);
$pid = (int)$pharmacy['id'];
$_SESSION['cart_by_store'] ??= [];
$_SESSION['cart_by_store'][$pid] ??= [];
$cart = &$_SESSION['cart_by_store'][$pid];
$flash = (string)($_SESSION['store_flash'] ?? '');
unset($_SESSION['store_flash']);
$paymentMethods = PaymentGateway::availableMethods();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add') {
        $id = (int)($_POST['id'] ?? 0);
        $qty = max(1, min(20, (int)($_POST['qty'] ?? 1)));
        $m = Catalog::storeProduct($db, $pid, $id);
        if ($m && (int)$m['store_active'] === 1 && (int)$m['store_stock'] >= $qty && (float)$m['store_price'] > 0) {
            $cart[$id] = min((int)$m['store_stock'], ($cart[$id] ?? 0) + $qty);
            $flash = 'Produto adicionado ao carrinho.';
        } else {
            $flash = 'Produto indisponível para compra nesta farmácia.';
        }
    }

    if ($action === 'clear') {
        $cart = [];
        $flash = 'Carrinho limpo.';
    }

    if ($action === 'checkout') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $dtype = in_array($_POST['delivery_type'] ?? 'delivery', ['delivery', 'pickup'], true) ? (string)$_POST['delivery_type'] : 'delivery';
        $address = trim((string)($_POST['address'] ?? ''));
        $city = trim((string)($_POST['city'] ?? ''));
        $state = strtoupper(trim((string)($_POST['state'] ?? '')));
        $zip = preg_replace('/\D+/', '', (string)($_POST['zip'] ?? ''));
        $requestedPay = (string)($_POST['payment_method'] ?? '');
        $pay = array_key_exists($requestedPay, $paymentMethods) ? $requestedPay : (string)array_key_first($paymentMethods);

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$cart) {
            $flash = 'Preencha os dados obrigatórios e mantenha itens no carrinho.';
        } elseif ($dtype === 'delivery' && ($address === '' || $city === '' || strlen($zip) < 8)) {
            $flash = 'Informe o endereço completo e um CEP válido para delivery.';
        } else {
            $items = [];
            $subtotal = 0.0;
            $needsRx = false;
            $controlled = false;
            $invalid = false;

            foreach ($cart as $id => $qty) {
                $m = Catalog::storeProduct($db, $pid, (int)$id);
                if (!$m || (int)$m['store_active'] !== 1 || (int)$m['store_stock'] < (int)$qty || (float)$m['store_price'] <= 0 || ($dtype === 'delivery' && (int)$m['remote_delivery_allowed'] !== 1)) {
                    $invalid = true;
                    break;
                }
                $price = (float)$m['store_price'];
                $subtotal += $price * (int)$qty;
                $needsRx = $needsRx || (int)$m['requires_prescription'] === 1 || (int)$m['controlled'] === 1;
                $controlled = $controlled || (int)$m['controlled'] === 1;
                $items[] = [$m, (int)$qty, $price];
            }

            if ($invalid) {
                $flash = 'Há item indisponível, sem preço, sem estoque ou não liberado para este fluxo.';
            } else {
                $rxPath = $rxName = $rxMime = $rxHash = null;
                if (!empty($_FILES['prescription']['tmp_name']) && is_uploaded_file($_FILES['prescription']['tmp_name'])) {
                    $f = $_FILES['prescription'];
                    $fi = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $fi->file($f['tmp_name']);
                    $allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
                    if (isset($allowed[$mime]) && (int)$f['size'] <= 8 * 1024 * 1024) {
                        $dir = private_state_dir() . '/uploads';
                        if (!is_dir($dir)) mkdir($dir, 0770, true);
                        $rxPath = $dir . '/rx_' . date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
                        if (move_uploaded_file($f['tmp_name'], $rxPath)) {
                            $rxName = basename((string)$f['name']);
                            $rxMime = $mime;
                            $rxHash = hash_file('sha256', $rxPath);
                        } else {
                            $rxPath = null;
                        }
                    }
                }

                if ($needsRx && !$rxPath) {
                    $flash = 'O pedido contém medicamento sob prescrição/controle. Envie a receita para avaliação farmacêutica antes da dispensação.';
                } else {
                    $quote = $dtype === 'delivery' ? Pharmacy::deliveryQuote($db, $pid, $zip, $city, $subtotal) : ['fee' => 0, 'zone' => 'Retirada', 'eta_min' => null, 'eta_max' => null];
                    $fee = (float)$quote['fee'];
                    $total = $subtotal + $fee;
                    $phStatus = $needsRx ? 'awaiting_review' : 'not_required';
                    $deliveryStatus = $needsRx ? 'blocked_pharmacist' : 'pending';
                    $publicToken = order_public_token();
                    $provider = $pay === 'pix' ? PaymentGateway::provider() : 'delivery';

                    $db->beginTransaction();
                    try {
                        $c = $db->prepare('INSERT INTO customers(pharmacy_id,name,email,phone) VALUES(?,?,?,?)');
                        $c->execute([$pid, $name, $email, $phone]);
                        $cid = (int)$db->lastInsertId();

                        $o = $db->prepare('INSERT INTO orders(pharmacy_id,customer_id,public_token,customer_name,customer_email,customer_phone,status,pharmacist_status,prescription_path,total,delivery_fee,payment_method,payment_provider,payment_status,delivery_type,delivery_status,delivery_address,delivery_city,delivery_state,delivery_zip,delivery_notes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                        $o->execute([$pid, $cid, $publicToken, $name, $email, $phone, 'pending', $phStatus, $rxPath, $total, $fee, $pay, $provider, 'pending', $dtype, $deliveryStatus, $address, $city, $state, $zip, trim((string)($_POST['delivery_notes'] ?? ''))]);
                        $oid = (int)$db->lastInsertId();

                        $it = $db->prepare('INSERT INTO order_items(order_id,medication_id,quantity,unit_price,requires_prescription,controlled) VALUES(?,?,?,?,?,?)');
                        $stock = $db->prepare('UPDATE pharmacy_products SET stock=stock-?,updated_at=CURRENT_TIMESTAMP WHERE pharmacy_id=? AND medication_id=? AND stock>=?');
                        foreach ($items as [$m, $qty, $price]) {
                            $it->execute([$oid, $m['id'], $qty, $price, (int)$m['requires_prescription'], (int)$m['controlled']]);
                            $stock->execute([$qty, $pid, $m['id'], $qty]);
                            if ($stock->rowCount() !== 1) throw new RuntimeException('Falha de estoque');
                        }

                        if ($rxPath) {
                            $p = $db->prepare('INSERT INTO prescriptions(pharmacy_id,customer_id,order_id,file_path,original_name,mime_type,sha256,status) VALUES(?,?,?,?,?,?,?,?)');
                            $p->execute([$pid, $cid, $oid, $rxPath, $rxName, $rxMime, $rxHash, 'awaiting_review']);
                        }

                        $db->commit();
                        Catalog::audit($db, 'order.created', 'order', (string)$oid, ['total' => $total, 'controlled' => $controlled, 'delivery' => $dtype, 'payment' => $pay]);
                        $cart = [];

                        if ($pay === 'pix') {
                            try {
                                PaymentGateway::createPix($db, $oid);
                            } catch (Throwable $e) {
                                $_SESSION['order_payment_notice'] = 'Pedido criado, mas o PIX não pôde ser gerado agora. Use o botão para tentar novamente.';
                            }
                        }

                        header('Location: ' . url('pedido.php?t=' . rawurlencode($publicToken)));
                        exit;
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) $db->rollBack();
                        if ($rxPath && is_file($rxPath)) @unlink($rxPath);
                        $flash = 'Não foi possível concluir o pedido. Tente novamente.';
                    }
                }
            }
        }
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$cat = trim((string)($_GET['cat'] ?? ''));
$categoryLabel = Catalog::categoryLabel($cat);
$meds = Catalog::publicSearch($db, $pid, $q, 80, $cat);
$categories = Catalog::consumerCategories();
$cartRows = [];
$subtotal = 0.0;
foreach ($cart as $id => $qty) {
    $m = Catalog::storeProduct($db, $pid, (int)$id);
    if ($m) {
        $price = (float)$m['store_price'];
        $subtotal += $price * $qty;
        $cartRows[] = [$m, $qty, $price];
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($pharmacy['name'])?></title>
<meta name="description" content="Farmácia online com busca no catálogo Anvisa, atendimento farmacêutico, retirada e delivery.">
<link rel="stylesheet" href="<?=h(url('assets/app.css'))?>">
</head>
<body>
<div class="utility-bar"><div class="wrap utility-inner"><span>Atendimento seguro e catálogo Anvisa</span><span>Retirada e delivery conforme disponibilidade da farmácia</span></div></div>
<header class="store-header">
  <div class="wrap store-head-main">
    <a class="brand" href="<?=h(url())?>"><span class="brand-mark">+</span><span><strong><?=h($pharmacy['name'])?></strong><small>Farmácia SuperAmplitude</small></span></a>
    <form class="head-search" method="get" role="search">
      <?php if($cat !== ''):?><input type="hidden" name="cat" value="<?=h($cat)?>"><?php endif;?>
      <input name="q" value="<?=h($q)?>" placeholder="O que você precisa? Busque por medicamento, princípio ativo ou laboratório" aria-label="Pesquisar medicamentos">
      <button type="submit">Buscar</button>
    </form>
    <div class="head-actions">
      <a href="#carrinho" class="head-action"><span>Entrega</span><b>Informe seu CEP</b></a>
      <a href="<?=h(url('admin.php'))?>" class="head-action"><span>Acesso</span><b>Painel</b></a>
      <a href="#carrinho" class="cart-action"><span>Carrinho</span><b><?=count($cart)?> item(ns)</b></a>
    </div>
  </div>
  <nav class="category-strip" aria-label="Categorias de medicamentos">
    <div class="wrap category-strip-inner">
      <a class="<?=($cat===''?'active':'')?>" href="<?=h(url())?>">Todos os medicamentos</a>
      <?php foreach($categories as $category):?>
        <a class="<?=($cat===$category['slug']?'active':'')?>" href="<?=h(url('?cat=' . rawurlencode($category['slug'])))?>"><?=h($category['name'])?></a>
      <?php endforeach;?>
    </div>
  </nav>
</header>

<main class="wrap">
<section class="hero storefront-hero"><div><span class="eyebrow">Farmácia online + avaliação farmacêutica</span><h1>Encontre medicamentos com rapidez e informação confiável.</h1><p>Consulte o catálogo cadastrado na Anvisa. Preço, estoque e compra só são liberados quando configurados pela farmácia.</p><form method="get" class="hero-search"><?php if($cat !== ''):?><input type="hidden" name="cat" value="<?=h($cat)?>"><?php endif;?><input name="q" value="<?=h($q)?>" placeholder="Ex.: dipirona, losartana, omeprazol..."><button>Pesquisar</button></form><div class="quick-links"><a href="?q=dipirona">Dipirona</a><a href="?q=paracetamol">Paracetamol</a><a href="?q=losartana">Losartana</a><a href="?q=omeprazol">Omeprazol</a></div></div></section>

<div class="service-highlights"><div><b>Busca em 32 mil+ cadastros</b><span>Nome, princípio ativo, laboratório e registro.</span></div><div><b>Receita protegida</b><span>Upload privado quando o medicamento exigir.</span></div><div><b>Retirada ou delivery</b><span>Fluxo preparado para disponibilidade local.</span></div><div><b>Atendimento farmacêutico</b><span>Medicamentos controlados passam por avaliação.</span></div></div>

<?php if($flash):?><div class="flash"><?=h($flash)?></div><?php endif;?>

<section class="catalog-section">
<div class="section-title"><div><span class="section-kicker">Catálogo</span><h2><?php if($q !== ''):?>Resultado para “<?=h($q)?>”<?php elseif($categoryLabel !== ''):?><?=h($categoryLabel)?><?php else:?>Medicamentos em destaque<?php endif;?></h2></div><span><?=count($meds)?> itens exibidos</span></div>
<?php if(!$meds):?><div class="empty">Nenhum medicamento correspondente foi encontrado na base.</div><?php else:?><div class="grid">
<?php foreach($meds as $m): $available=(int)$m['store_active']===1 && (int)$m['store_stock']>0 && (float)$m['store_price']>0;?>
<article class="card"><div class="product-media"><img loading="lazy" src="<?=h(med_image($m))?>" alt="<?=h($m['product_name'])?>"></div><div class="cardbody"><span class="tag"><?=h($m['regulatory_category']?:'Cadastro Anvisa')?></span><h3><?=h($m['product_name'])?></h3><p><?=h($m['active_ingredient'])?></p><small><?=h($m['company'])?><?php if(!empty($m['registration'])):?> · Reg. <?=h($m['registration'])?><?php endif;?></small><div class="flags"><?php if((int)$m['controlled']):?><b>Controle especial · exige avaliação</b><?php elseif((int)$m['requires_prescription']):?><b>Venda sob prescrição</b><?php else:?><b>Cadastro sanitário consultável</b><?php endif;?></div>
<?php if($available):?><div class="stock">Estoque: <?=h($m['store_stock'])?></div><div class="price">R$ <?=number_format((float)$m['store_price'],2,',','.')?></div><form method="post"><input type="hidden" name="_csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="add"><input type="hidden" name="id" value="<?=$m['id']?>"><button>Adicionar ao carrinho</button></form><?php else:?><div class="stock">Consulte disponibilidade, preço e estoque com a farmácia.</div><button disabled>Indisponível para compra online</button><?php endif;?>
</div></article>
<?php endforeach;?></div><?php endif;?>
</section>

<section id="carrinho" class="cart"><div class="section-title"><div><span class="section-kicker">Finalização</span><h2>Carrinho e entrega</h2></div><strong>Subtotal R$ <?=number_format($subtotal,2,',','.')?></strong></div>
<?php if(!$cartRows):?><p>Seu carrinho está vazio.</p><?php else:?><ul><?php foreach($cartRows as [$m,$qty,$price]):?><li><span><?=h($m['product_name'])?> × <?=$qty?></span><b>R$ <?=number_format($price*$qty,2,',','.')?></b></li><?php endforeach;?></ul>
<form method="post" enctype="multipart/form-data" class="checkout"><input type="hidden" name="_csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="checkout"><input name="name" placeholder="Nome completo" required><input type="email" name="email" placeholder="E-mail" required><input name="phone" placeholder="Telefone / WhatsApp"><select name="delivery_type"><option value="delivery">Delivery</option><option value="pickup">Retirada na farmácia</option></select><input name="zip" placeholder="CEP"><input name="address" placeholder="Rua, número e complemento"><input name="city" placeholder="Cidade"><input name="state" placeholder="UF" maxlength="2"><select name="payment_method"><?php foreach($paymentMethods as $value=>$label):?><option value="<?=h($value)?>"><?=h($label)?></option><?php endforeach;?></select><input name="delivery_notes" placeholder="Referência / observações"><label class="file">Receita (PDF/JPG/PNG), quando exigida <input type="file" name="prescription" accept=".pdf,.jpg,.jpeg,.png"></label><button>Enviar pedido para a farmácia</button></form>
<form method="post"><input type="hidden" name="_csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="clear"><button class="ghost">Limpar carrinho</button></form><?php endif;?></section>

<section class="trust"><div><b>Receita protegida</b><span>Arquivo privado, acessível somente à equipe autorizada.</span></div><div><b>Farmacêutico no fluxo</b><span>Pedidos que exigem receita ficam bloqueados até análise.</span></div><div><b>Delivery rastreável</b><span>Status operacional separado para pagamento, separação e entrega.</span></div></section>
<p class="notice">O chat oferece informação geral e pesquisa do catálogo. Ele não substitui médico ou farmacêutico, não prescreve e não define dose individual.</p>
</main>

<div id="ai-chat" class="chat" data-endpoint="<?=h(url('api/chat.php'))?>"><div class="chat-head"><div><b>Assistente da Farmácia</b><small>Pergunte sobre o catálogo</small></div><button type="button" class="chat-close" aria-label="Fechar chat" title="Fechar chat">×</button></div><div class="chat-log"><div class="msg ai"><p>Olá! Posso pesquisar medicamentos e mostrar dados cadastrais do catálogo. Para orientação clínica, a equipe farmacêutica valida a resposta.</p></div></div><form><input placeholder="Digite o nome ou sua dúvida..." autocomplete="off"><button>Enviar</button></form></div>
<button type="button" id="ai-chat-open" class="chat-open" hidden>Chat</button>
<script src="<?=h(url('assets/chat.js'))?>"></script>
<footer><div class="wrap footer-inner"><span><?=h($pharmacy['name'])?></span><span>Sistema SuperAmplitude</span></div></footer>
</body></html>
