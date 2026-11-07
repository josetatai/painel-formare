<?php
// Recebe os avisos de venda da Kiwify (webhook) e grava no painel.
// Guarda: nome, e-mail, WhatsApp e dados da compra. Não guarda CPF nem endereço.
require __DIR__ . '/lib.php';
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

// Busca um campo sem depender de maiúsculas/minúsculas.
function pega(array $a, string ...$caminho)
{
    foreach ($caminho as $c) {
        if (!is_array($a)) return null;
        $achou = null;
        foreach ($a as $k => $v) {
            if (strcasecmp((string)$k, $c) === 0) { $achou = $v; break; }
        }
        if ($achou === null) return null;
        $a = $achou;
    }
    return $a;
}

function data_kiwify($v): ?string
{
    if (!$v) return null;
    $t = strtotime((string)$v);
    return $t ? date('Y-m-d H:i:s', $t) : null;
}

$pedido = (string)(pega($d, 'order_id') ?? pega($d, 'order_ref') ?? '');
$evento = (string)(pega($d, 'webhook_event_type') ?? '');
if ($pedido === '') {
    registrar_aviso(null, $evento, false, 'Aviso sem número de pedido (ex.: carrinho abandonado)');
    http_response_code(200);
    exit;
}

$situacoes = [
    'paid' => 'Pago', 'approved' => 'Pago', 'waiting_payment' => 'Aguardando pagamento',
    'refused' => 'Recusado', 'refunded' => 'Reembolsado', 'chargedback' => 'Chargeback',
    'canceled' => 'Cancelado', 'pending_refund' => 'Reembolso pendente',
];
$porEvento = [
    'order_approved' => 'Pago', 'order_refunded' => 'Reembolsado', 'chargeback' => 'Chargeback',
    'billet_created' => 'Aguardando pagamento', 'pix_created' => 'Aguardando pagamento', 'order_rejected' => 'Recusado',
];
$st = strtolower((string)(pega($d, 'order_status') ?? ''));
$situacao = $situacoes[$st] ?? ($porEvento[strtolower($evento)] ?? ($st ?: $evento));

$pagamentos = ['pix' => 'Pix', 'credit_card' => 'Cartão', 'boleto' => 'Boleto', 'billet' => 'Boleto'];
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

db()->prepare('INSERT INTO vendas (pedido, produto_id, produto, valor_centavos, liquido_centavos, situacao, pagamento, nome, email, whatsapp,
                origem, campanha, midia, conteudo, src, sck, criado_em, aprovado_em, atualizado_em)
               VALUES (:pedido, :produto_id, :produto, :valor, :liquido, :situacao, :pagamento, :nome, :email, :whatsapp,
                :origem, :campanha, :midia, :conteudo, :src, :sck, :criado_em, :aprovado_em, :atualizado_em)
               ON CONFLICT(pedido, produto_id) DO UPDATE SET
                produto = excluded.produto, valor_centavos = COALESCE(excluded.valor_centavos, valor_centavos),
                liquido_centavos = COALESCE(excluded.liquido_centavos, liquido_centavos), situacao = excluded.situacao,
                pagamento = COALESCE(excluded.pagamento, pagamento), nome = excluded.nome, email = excluded.email,
                whatsapp = excluded.whatsapp, origem = COALESCE(excluded.origem, origem), campanha = COALESCE(excluded.campanha, campanha),
                midia = COALESCE(excluded.midia, midia), conteudo = COALESCE(excluded.conteudo, conteudo),
                src = COALESCE(excluded.src, src), sck = COALESCE(excluded.sck, sck),
                aprovado_em = COALESCE(excluded.aprovado_em, aprovado_em), atualizado_em = excluded.atualizado_em')
    ->execute(['produto_id' => $linha['produto_id']] + array_map(fn($v) => $v === '' ? null : $v, $linha));

registrar_aviso($pedido, $evento, true, $situacao);
http_response_code(200);
echo 'ok';
