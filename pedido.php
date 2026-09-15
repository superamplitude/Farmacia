<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

$token = trim((string)($_GET['t'] ?? $_POST['t'] ?? ''));
if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
    http_response_code(404);
    exit('Pedido não encontrado.');
}

$st = $db->prepare('SELECT * FROM orders WHERE public_token=? LIMIT 1');
$st->execute([$token]);
$order = $st->fetch();
if (!$order) {
    http_response_code(404);
    exit('Pedido não encontrado.');
}

$notice = (string)($_SESSION['order_payment_notice'] ?? '');
unset($_SESSION['order_payment_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'retry_pix' && ($order['payment_method'] ?? '') === 'pix' && ($order['payment_status'] ?? '') !== 'paid') {
        try {
            PaymentGateway::createPix($db, (int)$order['id']);
            $notice = 'PIX atualizado com sucesso.';
        } catch (Throwable $e) {
            $notice = 'Não foi possível gerar o PIX agora. Tente novamente em instantes.';
        }
        $st->execute([$token]);
        $order = $st->fetch() ?: $order;
    }
}

if (($order['payment_method'] ?? '') === 'pix' && !empty($order['payment_external_id']) && ($order['payment_status'] ?? '') === 'pending') {
    try {
        PaymentGateway::syncMercadoPago($db, (string)$order['payment_external_id']);
        $st->execute([$token]);
        $order = $st->fetch() ?: $order;
    } catch (Throwable) {
        // O rastreamento continua disponível mesmo se o provedor estiver temporariamente indisponível.
    }
}

$itemsSt = $db->prepare('SELECT oi.*,m.product_name,m.active_ingredient FROM order_items oi JOIN medications m ON m.id=oi.medication_id WHERE oi.order_id=? ORDER BY oi.id');
$itemsSt->execute([(int)$order['id']]);
$items = $itemsSt->fetchAll();
$payment = PaymentGateway::publicPaymentData($order);

function order_status_label(string $status): string {
    return match ($status) {
        'pending' => 'Pedido recebido',
        'approved' => 'Aprovado',
        'rejected' => 'Rejeitado',
        'separating' => 'Em separação',
        'ready' => 'Pronto',
        'delivered' => 'Entregue',
        'cancelled' => 'Cancelado',
        default => $status,
    };
}
function payment_status_label(string $status): string {
    return match ($status) {
        'paid' => 'Pago',
        'failed' => 'Falhou/cancelado',
        'refunded' => 'Estornado',
        default => 'Pendente',
    };
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pedido #<?=$order['id']?> · Farmácia SuperAmplitude</title>
<link rel="stylesheet" href="<?=h(url('assets/app.css'))?>">
</head>
<body>
<header><div class="wrap top"><div><strong>Farmácia SuperAmplitude</strong><small>Acompanhamento do pedido</small></div><nav><a href="<?=h(url())?>">Voltar à loja</a></nav></div></header>
<main class="wrap narrow">
<section class="panel">
<div class="section-title"><h1>Pedido #<?=$order['id']?></h1><strong><?=h(order_status_label((string)$order['status']))?></strong></div>
<?php if($notice):?><div class="flash"><?=h($notice)?></div><?php endif;?>
<div class="stats"><div><b><?=h(payment_status_label((string)$order['payment_status']))?></b><span>Pagamento</span></div><div><b><?=h((string)$order['pharmacist_status'])?></b><span>Avaliação farmacêutica</span></div><div><b><?=h((string)$order['delivery_status'])?></b><span>Entrega/retirada</span></div></div>
<p><b>Cliente:</b> <?=h($order['customer_name'])?></p>
<p><b>Forma:</b> <?=h($order['delivery_type']==='pickup'?'Retirada':'Delivery')?> · <b>Pagamento:</b> <?=h(PaymentGateway::methodLabel((string)$order['payment_method']))?></p>
<?php if($order['delivery_type']==='delivery'):?><p><b>Destino:</b> <?=h($order['delivery_address'])?>, <?=h($order['delivery_city'])?>/<?=h($order['delivery_state'])?> · CEP <?=h($order['delivery_zip'])?></p><?php endif;?>
<ul><?php foreach($items as $item):?><li><?=h($item['product_name'])?> × <?=h($item['quantity'])?> — R$ <?=number_format((float)$item['unit_price']*(int)$item['quantity'],2,',','.')?></li><?php endforeach;?></ul>
<p><b>Total:</b> R$ <?=number_format((float)$order['total'],2,',','.')?></p>
</section>

<?php if($order['payment_method']==='pix' && $order['payment_status']!=='paid'):?>
<section class="panel">
<h2>Pagamento PIX</h2>
<?php if($payment['qr_code_base64']!==''):?><p><img style="max-width:280px;width:100%;height:auto" src="data:image/png;base64,<?=h($payment['qr_code_base64'])?>" alt="QR Code PIX"></p><?php endif;?>
<?php if($payment['qr_code']!==''):?><label>Código PIX copia e cola</label><textarea readonly rows="5" style="width:100%" onclick="this.select()"><?=h($payment['qr_code'])?></textarea><?php endif;?>
<?php if($payment['ticket_url']!==''):?><p><a class="button" href="<?=h($payment['ticket_url'])?>" target="_blank" rel="noopener">Abrir instruções do pagamento</a></p><?php endif;?>
<?php if($payment['expires_at']):?><p><small>Expira em: <?=h($payment['expires_at'])?></small></p><?php endif;?>
<?php if($payment['qr_code']===''):?><form method="post"><input type="hidden" name="_csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="t" value="<?=h($token)?>"><input type="hidden" name="action" value="retry_pix"><button>Gerar PIX</button></form><?php endif;?>
</section>
<?php endif;?>

<?php if($order['pharmacist_status']==='awaiting_review'):?><div class="notice">A receita está aguardando avaliação farmacêutica. A dispensação e a entrega permanecem bloqueadas até a análise.</div><?php endif;?>
</main>
</body></html>
