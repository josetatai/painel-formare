<?php
// Importa o histórico de vendas pela API pública da Kiwify (somente leitura de vendas).
require_once __DIR__ . '/vendas_lib.php';

const KIWIFY_API = 'https://public-api.kiwify.com/v1/';

function kiwify_http(string $metodo, string $url, array $cab, ?string $corpo = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_HTTPHEADER => $cab, CURLOPT_CUSTOMREQUEST => $metodo]);
    if ($corpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);
    $r = curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$codigo, json_decode((string)$r, true), (string)$r];
}

function kiwify_token(): array
{
    $guardado = json_decode((string)ajuste('kiwify_api_token', ''), true);
    if (is_array($guardado) && ($guardado['ate'] ?? 0) > time() + 300) return ['token' => $guardado['token']];
    [$c, $j, $bruto] = kiwify_http('POST', KIWIFY_API . 'oauth/token', ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['client_id' => (string)ajuste('kiwify_client_id'), 'client_secret' => (string)ajuste('kiwify_client_secret')]));
    if ($c >= 400 || empty($j['access_token'])) return ['erro' => 'A Kiwify recusou a chave de API (código ' . $c . '). Confira Client ID e Client Secret.'];
    salvar_ajuste('kiwify_api_token', json_encode(['token' => $j['access_token'], 'ate' => time() + (int)($j['expires_in'] ?? 3600)]));
    return ['token' => $j['access_token']];
}

// Converte uma venda da API para o formato do painel.
function venda_da_api(array $v): array
{
    $st = strtolower((string)(pega($v, 'status') ?? ''));
    $pg = strtolower((string)(pega($v, 'payment_method') ?? ''));
    $tel = preg_replace('/[^0-9+]/', '', (string)(pega($v, 'customer', 'mobile') ?? pega($v, 'customer', 'phone') ?? ''));
    $bruto = pega($v, 'payment', 'charge_amount') ?? pega($v, 'payment', 'product_base_price') ?? pega($v, 'charge_amount');
    $liq = pega($v, 'net_amount') ?? pega($v, 'payment', 'net_amount');
    return [
        'pedido' => (string)(pega($v, 'id') ?? pega($v, 'order_id') ?? ''),
        'produto_id' => (string)(pega($v, 'product', 'id') ?? ''),
        'produto' => (string)(pega($v, 'product', 'name') ?? ''),
        'valor' => is_numeric($bruto) ? (int)$bruto : null,
        'liquido' => is_numeric($liq) ? (int)$liq : null,
        'situacao' => SITUACOES_KIWIFY[$st] ?? ($st ?: null),
        'pagamento' => PAGAMENTOS_KIWIFY[$pg] ?? ($pg ?: null),
        'nome' => mb_substr((string)(pega($v, 'customer', 'name') ?? pega($v, 'customer', 'full_name') ?? ''), 0, 120),
        'email' => mb_substr((string)(pega($v, 'customer', 'email') ?? ''), 0, 160),
        'whatsapp' => mb_substr($tel, 0, 20),
        'origem' => pega($v, 'tracking', 'utm_source'),
        'campanha' => pega($v, 'tracking', 'utm_campaign'),
        'midia' => pega($v, 'tracking', 'utm_medium'),
        'conteudo' => pega($v, 'tracking', 'utm_content'),
        'src' => pega($v, 'tracking', 'src'),
        'sck' => pega($v, 'tracking', 'sck'),
        'criado_em' => data_kiwify(pega($v, 'created_at')) ?? date('Y-m-d H:i:s'),
        'aprovado_em' => data_kiwify(pega($v, 'approved_date') ?? pega($v, 'paid_at')),
        'atualizado_em' => date('Y-m-d H:i:s'),
    ];
}

// Busca todas as vendas desde $desde (em janelas de 90 dias, que é o limite da API).
function importar_kiwify(string $desde): array
{
    @set_time_limit(300);
    $t = kiwify_token();
    if (isset($t['erro'])) return $t;
    $cab = ['Authorization: Bearer ' . $t['token'], 'x-kiwify-account-id: ' . (string)ajuste('kiwify_account_id'), 'Accept: application/json'];
    $total = 0;
    $ini = strtotime($desde);
    while ($ini <= time()) {
        $fim = min(time(), strtotime('+89 days', $ini));
        for ($p = 1; $p <= 200; $p++) {
            $url = KIWIFY_API . 'sales?' . http_build_query(['start_date' => date('Y-m-d', $ini), 'end_date' => date('Y-m-d', $fim),
                'page_size' => 100, 'page_number' => $p, 'view_full_sale_details' => 'true']);
            [$c, $j, $bruto] = kiwify_http('GET', $url, $cab);
            if ($c >= 400 || !is_array($j)) {
                return ['erro' => 'A Kiwify respondeu com erro (código ' . $c . '): ' . mb_substr(strip_tags($bruto), 0, 200), 'importadas' => $total];
            }
            $lista = $j['data'] ?? [];
            foreach ($lista as $v) {
                $linha = venda_da_api((array)$v);
                if ($linha['pedido'] === '') continue;
                salvar_venda($linha);
                $total++;
            }
            if (count($lista) < 100) break;
        }
        $ini = strtotime('+1 day', $fim);
    }
    salvar_ajuste('kiwify_importado_em', date('Y-m-d H:i:s'));
    return ['importadas' => $total];
}
