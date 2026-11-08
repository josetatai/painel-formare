<?php
// Busca as campanhas e os números da Meta (Facebook/Instagram), com cache de 15 minutos.

// Versão da Marketing API. Quando a Meta descontinuar esta, a aba Campanhas mostra o erro #2635 com a versão nova: troque aqui.
const META_API_VERSAO = 'v27.0';

function meta_get(string $caminho, array $params): array
{
    $token = (string)ajuste('meta_token', '');
    if ($token === '') return ['erro' => 'Falta cadastrar o token da Meta em Ajustes.'];
    $url = 'https://graph.facebook.com/' . META_API_VERSAO . '/' . ltrim($caminho, '/') . '?' . http_build_query($params + ['access_token' => $token]);
    $todos = [];
    for ($pagina = 0; $pagina < 10 && $url; $pagina++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25]);
        $r = curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = json_decode((string)$r, true);
        if (!is_array($j) || $codigo >= 400) {
            $msg = $j['error']['message'] ?? ('Resposta inesperada da Meta (código ' . $codigo . ')');
            return ['erro' => $msg];
        }
        $todos = array_merge($todos, $j['data'] ?? []);
        $url = $j['paging']['next'] ?? null;
    }
    return ['dados' => $todos];
}

function meta_campanhas(string $de, string $ate, bool $forcar = false): array
{
    $contas = array_filter(array_map('trim', explode(',', (string)ajuste('meta_contas', META_CONTAS_PADRAO))));
    $chave = 'meta2:' . implode(',', $contas) . ':' . $de . ':' . $ate;
    if (!$forcar) {
        $s = db()->prepare('SELECT valor, atualizado_em FROM cache WHERE chave = ?');
        $s->execute([$chave]);
        if (($c = $s->fetch()) && $c['atualizado_em'] > time() - 15 * 60) {
            return json_decode($c['valor'], true) + ['atualizado_em' => $c['atualizado_em']];
        }
    }
    $linhas = [];
    $erros = [];
    $alertas = [];
    $anuncios = [];
    foreach ($contas as $conta) {
        $conta = 'act_' . preg_replace('/\D/', '', $conta);
        $info = meta_get($conta . '/campaigns', ['fields' => 'name,effective_status,objective', 'limit' => 200]);
        if (isset($info['erro'])) { $erros[] = $conta . ': ' . $info['erro']; continue; }
        $status = [];
        foreach ($info['dados'] as $c) $status[$c['id']] = $c;
        $ins = meta_get($conta . '/insights', [
            'level' => 'campaign',
            'fields' => 'campaign_id,campaign_name,spend,impressions,reach,clicks,inline_link_clicks,ctr,cpc,cpm,actions,action_values',
            'time_range' => json_encode(['since' => $de, 'until' => $ate]),
            'limit' => 200,
        ]);
        if (isset($ins['erro'])) { $erros[] = $conta . ': ' . $ins['erro']; continue; }

        $cinfo = meta_get($conta, ['fields' => 'name,account_status,disable_reason']);
        $statusConta = [1 => 'Ativa', 2 => 'Desativada', 3 => 'Pagamento pendente', 7 => 'Em análise de risco', 8 => 'Pagamento pendente', 9 => 'Em período de carência', 100 => 'Fechamento pendente', 101 => 'Fechada'];
        if (!isset($cinfo['erro'])) {
            $st = (int)($cinfo['dados']['account_status'] ?? 1);
            if ($st !== 1) $alertas[] = 'Conta ' . $conta . ': ' . ($statusConta[$st] ?? 'situação ' . $st);
        }
        $ads = meta_get($conta . '/ads', ['fields' => 'name,effective_status,campaign_id', 'limit' => 500]);
        $adStatus = [];
        foreach ($ads['dados'] ?? [] as $ad) {
            $adStatus[$ad['id']] = $ad['effective_status'] ?? '';
            if (in_array($ad['effective_status'] ?? '', ['DISAPPROVED', 'WITH_ISSUES', 'PENDING_REVIEW'], true)) {
                $nomes = ['DISAPPROVED' => 'reprovado', 'WITH_ISSUES' => 'com problema', 'PENDING_REVIEW' => 'em análise'];
                $alertas[] = 'Anúncio "' . $ad['name'] . '" ' . $nomes[$ad['effective_status']];
            }
        }
        $insAd = meta_get($conta . '/insights', [
            'level' => 'ad',
            'fields' => 'ad_id,ad_name,campaign_name,spend,impressions,inline_link_clicks,actions',
            'time_range' => json_encode(['since' => $de, 'until' => $ate]),
            'limit' => 500,
        ]);
        foreach ($insAd['dados'] ?? [] as $i) {
            $ac = [];
            foreach ($i['actions'] ?? [] as $x) $ac[$x['action_type']] = (float)$x['value'];
            $anuncios[] = [
                'nome' => $i['ad_name'], 'campanha' => $i['campaign_name'], 'status' => $adStatus[$i['ad_id']] ?? '',
                'gasto' => (float)($i['spend'] ?? 0), 'impressoes' => (int)($i['impressions'] ?? 0),
                'cliques_link' => (int)($i['inline_link_clicks'] ?? 0),
                'compras' => (int)($ac['offsite_conversion.fb_pixel_purchase'] ?? $ac['purchase'] ?? 0),
            ];
        }
        $vistos = [];
        foreach ($ins['dados'] as $i) {
            $acoes = [];
            foreach ($i['actions'] ?? [] as $a) $acoes[$a['action_type']] = (float)$a['value'];
            $valores = [];
            foreach ($i['action_values'] ?? [] as $a) $valores[$a['action_type']] = (float)$a['value'];
            $id = $i['campaign_id'];
            $vistos[$id] = true;
            $linhas[] = [
                'conta' => $conta,
                'id' => $id,
                'nome' => $i['campaign_name'],
                'status' => $status[$id]['effective_status'] ?? '',
                'objetivo' => $status[$id]['objective'] ?? '',
                'gasto' => (float)($i['spend'] ?? 0),
                'impressoes' => (int)($i['impressions'] ?? 0),
                'alcance' => (int)($i['reach'] ?? 0),
                'cliques_link' => (int)($i['inline_link_clicks'] ?? 0),
                'ctr' => (float)($i['ctr'] ?? 0),
                'cpc' => (float)($i['cpc'] ?? 0),
                'cpm' => (float)($i['cpm'] ?? 0),
                'visitas_lp' => (int)($acoes['landing_page_view'] ?? 0),
                'checkouts' => (int)($acoes['offsite_conversion.fb_pixel_initiate_checkout'] ?? $acoes['initiate_checkout'] ?? 0),
                'compras' => (int)($acoes['offsite_conversion.fb_pixel_purchase'] ?? $acoes['purchase'] ?? 0),
                'valor_compras' => (float)($valores['offsite_conversion.fb_pixel_purchase'] ?? $valores['purchase'] ?? 0),
            ];
        }
        foreach ($status as $id => $c) {
            if (empty($vistos[$id]) && ($c['effective_status'] ?? '') === 'ACTIVE') {
                $linhas[] = ['conta' => $conta, 'id' => $id, 'nome' => $c['name'], 'status' => 'ACTIVE', 'objetivo' => $c['objective'] ?? '',
                    'gasto' => 0, 'impressoes' => 0, 'alcance' => 0, 'cliques_link' => 0, 'ctr' => 0, 'cpc' => 0, 'cpm' => 0,
                    'visitas_lp' => 0, 'checkouts' => 0, 'compras' => 0, 'valor_compras' => 0];
            }
        }
    }
    usort($linhas, fn($a, $b) => $b['gasto'] <=> $a['gasto']);
    usort($anuncios, fn($a, $b) => $b['gasto'] <=> $a['gasto']);
    $res = ['linhas' => $linhas, 'erros' => $erros, 'alertas' => $alertas, 'anuncios' => $anuncios];
    if (!$erros) {
        db()->prepare('INSERT INTO cache (chave, valor, atualizado_em) VALUES (?, ?, ?) ON CONFLICT(chave) DO UPDATE SET valor = excluded.valor, atualizado_em = excluded.atualizado_em')
            ->execute([$chave, json_encode($res), time()]);
    }
    return $res + ['atualizado_em' => time()];
}
