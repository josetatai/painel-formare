<?php
// Recebe os avisos de venda da Kiwify (webhook) e grava no painel.
// Guarda: nome, e-mail, WhatsApp e dados da compra. Não guarda CPF nem endereço.
require __DIR__ . '/lib.php';
require __DIR__ . '/vendas_lib.php';
header('Cache-Control: no-store');

function registrar_aviso(?string $pedido, ?string $evento, bool $aceito, string $motivo): void
{
    db()->prepare('INSERT INTO avisos_kiwify (recebido_em, pedido, evento, aceito, motivo) VALUES (?, ?, ?, ?, ?)')
        ->execute([date('Y-m-d H:i:s'), $pedido, $evento, $aceito ? 1 : 0, $motivo]);
    db()->exec("DELETE FROM avisos_kiwify WHERE id NOT IN (SELECT id FROM avisos_kiwify ORDER BY id DESC LIMIT 500)");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Use POST.');
}

$bruto = (string)file_get_contents('php://input');
$token = (string)ajuste('kiwify_token', '');
$assinatura = strtolower((string)($_GET['signature'] ?? ''));
if ($token === '') {
    registrar_aviso(null, null, false, 'Token da Kiwify não cadastrado no painel');
    http_response_code(503);
    exit;
}
if (!hash_equals(hash_hmac('sha1', $bruto, $token), $assinatura)) {
    registrar_aviso(null, null, false, 'Assinatura inválida');
    http_response_code(401);
    exit;
}

$d = json_decode($bruto, true);
if (!is_array($d)) {
    registrar_aviso(null, null, false, 'Conteúdo não é JSON');
    http_response_code(400);
    exit;
}
if (isset($d['order']) && is_array($d['order'])) $d = $d['order'];

$pedido = (string)(pega($d, 'order_id') ?? pega($d, 'order_ref') ?? '');
$evento = (string)(pega($d, 'webhook_event_type') ?? '');
if ($pedido === '') {
    // Carrinho abandonado: a pessoa preencheu os dados no checkout e não concluiu.
    $email = strtolower(trim((string)(pega($d, 'email') ?? pega($d, 'Customer', 'email') ?? '')));
    $tel = preg_replace('/[^0-9+]/', '', (string)(pega($d, 'phone') ?? pega($d, 'mobile') ?? pega($d, 'Customer', 'mobile') ?? ''));
    if ($email !== '' || $tel !== '') {
        $produto = (string)(pega($d, 'product_name') ?? pega($d, 'Product', 'product_name') ?? '');
        db()->prepare('INSERT INTO carrinhos (chave, criado_em, produto, nome, email, whatsapp, link_checkout) VALUES (?, ?, ?, ?, ?, ?, ?)
                       ON CONFLICT(chave) DO UPDATE SET criado_em = excluded.criado_em, nome = excluded.nome, whatsapp = excluded.whatsapp, link_checkout = excluded.link_checkout')
            ->execute([(string)(pega($d, 'id') ?? pega($d, 'checkout_id') ?? ($email . '|' . $produto)), data_kiwify(pega($d, 'created_at')) ?? date('Y-m-d H:i:s'),
                $produto, mb_substr((string)(pega($d, 'name') ?? pega($d, 'Customer', 'full_name') ?? ''), 0, 120), mb_substr($email, 0, 160),
                mb_substr($tel, 0, 20), mb_substr((string)(pega($d, 'checkout_link') ?? ''), 0, 300) ?: null]);
        registrar_aviso(null, 'carrinho_abandonado', true, 'Carrinho abandonado');
        http_response_code(200);
        exit;
    }
    registrar_aviso(null, $evento, false, 'Aviso sem número de pedido (ex.: carrinho abandonado)');
    http_response_code(200);
    exit;
}

$situacoes = SITUACOES_KIWIFY;
$porEvento = [
    'order_approved' => 'Pago', 'order_refunded' => 'Reembolsado', 'chargeback' => 'Chargeback',
    'billet_created' => 'Aguardando pagamento', 'pix_created' => 'Aguardando pagamento', 'order_rejected' => 'Recusado',
];
$st = strtolower((string)(pega($d, 'order_status') ?? ''));
$situacao = $situacoes[$st] ?? ($porEvento[strtolower($evento)] ?? ($st ?: $evento));

$pagamentos = PAGAMENTOS_KIWIFY;
$pg = strtolower((string)(pega($d, 'payment_method') ?? ''));

$valor = pega($d, 'Commissions', 'charge_amount') ?? pega($d, 'Commissions', 'product_base_price');
$liquido = pega($d, 'Commissions', 'my_commission');
$telefone = (string)(pega($d, 'Customer', 'mobile') ?? '');
$telefone = preg_replace('/[^0-9+]/', '', $telefone);

$linha = [
    'pedido' => $pedido,
    'produto_id' => (string)(pega($d, 'Product', 'product_id') ?? ''),
    'produto' => (string)(pega($d, 'Product', 'product_name') ?? ''),
    'valor' => is_numeric($valor) ? (int)$valor : null,
    'liquido' => is_numeric($liquido) ? (int)$liquido : null,
    'situacao' => $situacao,
    'pagamento' => $pagamentos[$pg] ?? ($pg ?: null),
    'nome' => mb_substr((string)(pega($d, 'Customer', 'full_name') ?? pega($d, 'Customer', 'first_name') ?? ''), 0, 120),
    'email' => mb_substr((string)(pega($d, 'Customer', 'email') ?? ''), 0, 160),
    'whatsapp' => mb_substr($telefone, 0, 20),
    'origem' => pega($d, 'TrackingParameters', 'utm_source'),
    'campanha' => pega($d, 'TrackingParameters', 'utm_campaign'),
    'midia' => pega($d, 'TrackingParameters', 'utm_medium'),
    'conteudo' => pega($d, 'TrackingParameters', 'utm_content'),
    'src' => pega($d, 'TrackingParameters', 'src'),
    'sck' => pega($d, 'TrackingParameters', 'sck'),
    'criado_em' => data_kiwify(pega($d, 'created_at')) ?? date('Y-m-d H:i:s'),
    'aprovado_em' => data_kiwify(pega($d, 'approved_date')),
    'atualizado_em' => date('Y-m-d H:i:s'),
];

salvar_venda($linha);

registrar_aviso($pedido, $evento, true, $situacao);
http_response_code(200);
echo 'ok';
