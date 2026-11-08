<?php
// Funções comuns para gravar vendas da Kiwify (webhook e importação pela API).

const SITUACOES_KIWIFY = [
    'paid' => 'Pago', 'approved' => 'Pago', 'waiting_payment' => 'Aguardando pagamento',
    'refused' => 'Recusado', 'refunded' => 'Reembolsado', 'chargedback' => 'Chargeback',
    'canceled' => 'Cancelado', 'pending_refund' => 'Reembolso pendente',
];
const PAGAMENTOS_KIWIFY = ['pix' => 'Pix', 'credit_card' => 'Cartão', 'boleto' => 'Boleto', 'billet' => 'Boleto'];

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

function salvar_venda(array $linha): void
{
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
}
