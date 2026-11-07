<?php
// Painel Instituto Formare: landing pages, campanhas, vendas e ajustes.
require __DIR__ . '/lib.php';
require __DIR__ . '/meta.php';
exigir_login();

$abas = ['paginas' => 'Landing pages', 'campanhas' => 'Campanhas', 'vendas' => 'Vendas', 'ajustes' => 'Ajustes'];
$aba = $_GET['aba'] ?? 'paginas';
if (!isset($abas[$aba])) $aba = 'paginas';

// Período
$hoje = date('Y-m-d');
$atalhos = ['hoje' => [$hoje, $hoje], 'ontem' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
    '7d' => [date('Y-m-d', strtotime('-6 days')), $hoje], '30d' => [date('Y-m-d', strtotime('-29 days')), $hoje],
    'mes' => [date('Y-m-01'), $hoje]];
$per = $_GET['per'] ?? '7d';
if (isset($atalhos[$per])) {
    [$de, $ate] = $atalhos[$per];
} else {
    $per = 'livre';
    $de = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['de'] ?? '') ? $_GET['de'] : $atalhos['7d'][0];
    $ate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['ate'] ?? '') ? $_GET['ate'] : $hoje;
}
$ini = $de . ' 00:00:00';
$fim = $ate . ' 23:59:59';

function q(string $sql, array $p = []): array
{
    $s = db()->prepare($sql);
    $s->execute($p);
    return $s->fetchAll();
}
function link_aba(string $a, array $extra = []): string
{
    $p = array_merge($_GET, ['aba' => $a], $extra);
    return '?' . http_build_query($p);
}

// Ajustes (salvar)
$aviso = '';
if ($aba === 'ajustes' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    conferir_form();
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'kiwify' && trim((string)$_POST['kiwify_token']) !== '') {
        salvar_ajuste('kiwify_token', trim((string)$_POST['kiwify_token']));
        $aviso = 'Token da Kiwify salvo.';
    } elseif ($acao === 'meta') {
        if (trim((string)($_POST['meta_token'] ?? '')) !== '') salvar_ajuste('meta_token', trim((string)$_POST['meta_token']));
        $contas = preg_replace('/[^0-9,]/', '', (string)($_POST['meta_contas'] ?? ''));
        salvar_ajuste('meta_contas', $contas !== '' ? $contas : META_CONTAS_PADRAO);
        db()->exec("DELETE FROM cache WHERE chave LIKE 'meta:%'");
        $aviso = 'Dados da Meta salvos.';
    } elseif ($acao === 'senha') {
        $atual = (string)($_POST['atual'] ?? '');
        $nova = (string)($_POST['nova'] ?? '');
        if (!password_verify($atual, (string)ajuste('senha_hash'))) $aviso = 'A senha atual está incorreta.';
        elseif (mb_strlen($nova) < 10) $aviso = 'A nova senha precisa ter pelo menos 10 caracteres.';
        else { salvar_ajuste('senha_hash', password_hash($nova, PASSWORD_DEFAULT)); $aviso = 'Senha trocada.'; }
    }
}

cabecalho($abas[$aba]);
?>
<header class="bg-white shadow-sm">
  <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between gap-3">
    <div class="flex items-center gap-3">
      <img src="../livro-licitacoes/img/logo-formare.webp" alt="Instituto Formare" class="h-9 w-auto">
      <span class="hidden sm:inline font-display text-xl text-navy">Painel</span>
    </div>
    <div class="flex items-center gap-3 text-sm">
      <span class="text-navy/60 hidden sm:inline">Olá, <?= h(ajuste('usuario')) ?></span>
      <a href="sair.php" class="btn2 !py-1.5">Sair</a>
    </div>
  </div>
  <nav class="max-w-7xl mx-auto px-4 pb-3 flex gap-2 overflow-x-auto">
    <?php foreach ($abas as $k => $n): ?>
      <a href="<?= h(link_aba($k)) ?>" class="tab <?= $k === $aba ? 'tab-on' : 'tab-off ring-1 ring-navy/10' ?>"><?= h($n) ?></a>
    <?php endforeach; ?>
  </nav>
</header>

<main class="max-w-7xl mx-auto px-4 py-6 space-y-6">

<?php if ($aba !== 'ajustes'): ?>
  <form class="card p-4 flex flex-wrap items-end gap-3" method="get">
    <input type="hidden" name="aba" value="<?= h($aba) ?>">
    <div class="flex flex-wrap gap-2">
      <?php foreach (['hoje' => 'Hoje', 'ontem' => 'Ontem', '7d' => '7 dias', '30d' => '30 dias', 'mes' => 'Este mês'] as $k => $n): ?>
        <a href="?<?= h(http_build_query(['aba' => $aba, 'per' => $k])) ?>" class="tab <?= $per === $k ? 'tab-on' : 'tab-off ring-1 ring-navy/10' ?>"><?= $n ?></a>
      <?php endforeach; ?>
    </div>
    <div class="flex items-end gap-2 text-sm">
      <label class="grid gap-1 font-semibold text-navy">De <input type="date" name="de" value="<?= h($de) ?>"></label>
      <label class="grid gap-1 font-semibold text-navy">Até <input type="date" name="ate" value="<?= h($ate) ?>"></label>
      <input type="hidden" name="per" value="livre">
      <button class="btn !py-2">Ver</button>
    </div>
  </form>
<?php endif; ?>

<?php if ($aba === 'paginas'):
    $porPagina = q("SELECT pagina,
        COUNT(DISTINCT CASE WHEN tipo='visita' THEN sessao END) visitas,
        COUNT(DISTINCT CASE WHEN tipo='visita' THEN visitante END) pessoas,
        COUNT(DISTINCT CASE WHEN tipo='tempo' AND segundos>=30 THEN sessao END) t30,
        COUNT(DISTINCT CASE WHEN tipo='tempo' AND segundos>=60 THEN sessao END) t60,
        COUNT(DISTINCT CASE WHEN tipo='secao' AND detalhe='oferta' THEN sessao END) preco,
        COUNT(CASE WHEN tipo='clique' THEN 1 END) cliques,
        COUNT(DISTINCT CASE WHEN tipo='clique' THEN sessao END) quem_clicou
        FROM eventos WHERE criado_em BETWEEN ? AND ? GROUP BY pagina ORDER BY visitas DESC", [$ini, $fim]);
    $secoesNomes = ['inicio' => 'Início', 'para-quem' => 'Para quem é', 'por-que' => 'Por que importa', 'volumes' => 'O que você leva',
        'modelos' => 'Modelos', 'bonus' => 'Bônus', 'espie' => 'Espie por dentro', 'acesso' => 'Como funciona o acesso',
        'oferta' => 'Preço (bloco de compra)', 'garantia' => 'Garantia', 'faq' => 'Perguntas', 'final' => 'Convite final'];
?>
  <section>
    <h1 class="font-display text-3xl text-navy">Landing pages</h1>
    <p class="text-sm text-navy/60 mt-1">Contagem própria do painel. Uma "visita" é cada vez que alguém abre a página; "pessoas" conta cada navegador uma vez.</p>
  </section>

  <?php if (!$porPagina): ?>
    <div class="card p-6 text-navy/70">Nenhuma visita registrada neste período.</div>
  <?php endif; ?>

  <?php foreach ($porPagina as $pg):
      $p = $pg['pagina'];
      $funil = q("SELECT detalhe, COUNT(DISTINCT sessao) n FROM eventos WHERE pagina=? AND tipo='secao' AND criado_em BETWEEN ? AND ? GROUP BY detalhe", [$p, $ini, $fim]);
      $funilMap = array_column($funil, 'n', 'detalhe');
      $botoes = q("SELECT detalhe, COUNT(*) n FROM eventos WHERE pagina=? AND tipo='clique' AND criado_em BETWEEN ? AND ? GROUP BY detalhe ORDER BY n DESC", [$p, $ini, $fim]);
      $origens = q("SELECT origem, COUNT(DISTINCT CASE WHEN tipo='visita' THEN sessao END) visitas, COUNT(DISTINCT CASE WHEN tipo='clique' THEN sessao END) cliques
                    FROM eventos WHERE pagina=? AND criado_em BETWEEN ? AND ? GROUP BY origem ORDER BY visitas DESC", [$p, $ini, $fim]);
      $aparelhos = q("SELECT aparelho, COUNT(DISTINCT sessao) n FROM eventos WHERE pagina=? AND tipo='visita' AND criado_em BETWEEN ? AND ? GROUP BY aparelho ORDER BY n DESC", [$p, $ini, $fim]);
      $tempoClique = q("SELECT AVG(segundos) m FROM eventos WHERE pagina=? AND tipo='clique' AND criado_em BETWEEN ? AND ?", [$p, $ini, $fim])[0]['m'];
      $dias = q("SELECT substr(criado_em,1,10) dia, COUNT(DISTINCT CASE WHEN tipo='visita' THEN sessao END) v, COUNT(DISTINCT CASE WHEN tipo='clique' THEN sessao END) c
                 FROM eventos WHERE pagina=? AND criado_em BETWEEN ? AND ? GROUP BY dia ORDER BY dia", [$p, $ini, $fim]);
      $v = (int)$pg['visitas'];
  ?>
  <article class="card p-5 sm:p-6">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
      <h2 class="font-display text-2xl text-navy"><?= h($p) ?></h2>
      <a class="text-sm text-leaf underline" href="https://land.institutoformare.com.br<?= h($p) ?>" target="_blank" rel="noopener">abrir página</a>
    </div>

    <div class="mt-5 grid grid-cols-2 md:grid-cols-6 gap-3">
      <?php foreach ([['Visitas', $v, ''], ['Pessoas', $pg['pessoas'], ''], ['Ficaram 30 s+', $pg['t30'], pct($pg['t30'], $v)],
          ['Ficaram 1 min+', $pg['t60'], pct($pg['t60'], $v)], ['Chegaram ao preço', $pg['preco'], pct($pg['preco'], $v)],
          ['Clicaram para comprar', $pg['quem_clicou'], pct($pg['quem_clicou'], $v)]] as [$n, $val, $sub]): ?>
        <div class="rounded-2xl bg-mint/50 p-4">
          <p class="text-xs font-semibold text-navy/70"><?= $n ?></p>
          <p class="font-display text-3xl text-navy mt-1"><?= numero($val) ?></p>
          <?php if ($sub): ?><p class="text-xs text-navy/60"><?= $sub ?> das visitas</p><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="mt-3 text-sm text-navy/70">Total de cliques em botões de compra: <strong><?= numero($pg['cliques']) ?></strong><?php if ($tempoClique): ?> · tempo médio até o clique: <strong><?= numero($tempoClique) ?> s</strong><?php endif; ?></p>

    <div class="mt-6 grid lg:grid-cols-2 gap-6">
      <div>
        <h3 class="font-bold text-navy">Até onde as pessoas leem</h3>
        <div class="mt-3 space-y-2">
          <?php foreach ($secoesNomes as $k => $n): $x = (int)($funilMap[$k] ?? 0); ?>
            <div class="grid grid-cols-[170px_1fr_90px] items-center gap-2 text-sm">
              <span class="text-navy/80"><?= $n ?></span>
              <div class="bg-navy/5 rounded-full"><div class="barra" style="width: <?= $v ? min(100, round(100 * $x / $v)) : 0 ?>%"></div></div>
              <span class="text-right text-navy/70"><?= numero($x) ?> · <?= pct($x, $v) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="space-y-6">
        <div>
          <h3 class="font-bold text-navy">Cliques por botão</h3>
          <table class="t mt-2"><tr><th>Botão</th><th class="n">Cliques</th></tr>
            <?php foreach ($botoes as $b): ?><tr><td><?= h($b['detalhe']) ?></td><td class="n"><?= numero($b['n']) ?></td></tr><?php endforeach; ?>
            <?php if (!$botoes): ?><tr><td colspan="2" class="text-navy/50">Nenhum clique no período.</td></tr><?php endif; ?>
          </table>
        </div>
        <div>
          <h3 class="font-bold text-navy">De onde vieram</h3>
          <table class="t mt-2"><tr><th>Origem</th><th class="n">Visitas</th><th class="n">Clicaram</th><th class="n">Taxa</th></tr>
            <?php foreach ($origens as $o): ?><tr><td><?= h($o['origem']) ?></td><td class="n"><?= numero($o['visitas']) ?></td><td class="n"><?= numero($o['cliques']) ?></td><td class="n"><?= pct($o['cliques'], $o['visitas']) ?></td></tr><?php endforeach; ?>
          </table>
        </div>
        <div class="flex flex-wrap gap-2 text-sm">
          <?php foreach ($aparelhos as $a): ?><span class="rounded-full bg-navy/5 px-3 py-1"><?= h($a['aparelho']) ?>: <strong><?= numero($a['n']) ?></strong> (<?= pct($a['n'], $v) ?>)</span><?php endforeach; ?>
        </div>
      </div>
    </div>

    <h3 class="font-bold text-navy mt-6">Dia a dia</h3>
    <div class="overflow-x-auto"><table class="t mt-2"><tr><th>Dia</th><th class="n">Visitas</th><th class="n">Clicaram para comprar</th><th class="n">Taxa</th></tr>
      <?php foreach ($dias as $d): ?><tr><td><?= date('d/m', strtotime($d['dia'])) ?></td><td class="n"><?= numero($d['v']) ?></td><td class="n"><?= numero($d['c']) ?></td><td class="n"><?= pct($d['c'], $d['v']) ?></td></tr><?php endforeach; ?>
    </table></div>
  </article>
  <?php endforeach; ?>

<?php elseif ($aba === 'campanhas'):
    $forcar = isset($_GET['atualizar']);
    $m = meta_campanhas($de, $ate, $forcar);
    $linhas = $m['linhas'] ?? [];
    $tot = ['gasto' => 0, 'impressoes' => 0, 'alcance' => 0, 'cliques_link' => 0, 'visitas_lp' => 0, 'checkouts' => 0, 'compras' => 0, 'valor_compras' => 0];
    foreach ($linhas as $l) foreach ($tot as $k => $_) $tot[$k] += $l[$k];
    $kiwPago = q("SELECT COUNT(*) n, COALESCE(SUM(valor_centavos),0) v FROM vendas WHERE situacao='Pago' AND criado_em BETWEEN ? AND ?", [$ini, $fim])[0];
    $statusPt = ['ACTIVE' => 'Ativa', 'PAUSED' => 'Pausada', 'CAMPAIGN_PAUSED' => 'Pausada', 'ARCHIVED' => 'Arquivada', 'DELETED' => 'Excluída', 'IN_PROCESS' => 'Em análise', 'WITH_ISSUES' => 'Com problema'];
?>
  <section class="flex flex-wrap items-end justify-between gap-3">
    <div>
      <h1 class="font-display text-3xl text-navy">Campanhas (Meta)</h1>
      <p class="text-sm text-navy/60 mt-1">Todas as campanhas das contas de anúncio cadastradas em Ajustes. Atualizado <?= isset($m['atualizado_em']) ? date('d/m H:i', (int)$m['atualizado_em']) : '' ?>.</p>
    </div>
    <a class="btn2" href="<?= h(link_aba('campanhas', ['atualizar' => 1])) ?>">Atualizar agora</a>
  </section>

  <?php foreach ($m['erros'] ?? [] as $e): ?><div class="card p-4 text-coral font-semibold"><?= h($e) ?></div><?php endforeach; ?>
  <?php if (isset($m['erro'])): ?><div class="card p-4 text-coral font-semibold"><?= h($m['erro']) ?></div><?php endif; ?>

  <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-3">
    <?php foreach ([['Gasto', reais((int)round($tot['gasto'] * 100))], ['Impressões', numero($tot['impressoes'])], ['Alcance*', numero($tot['alcance'])],
        ['Cliques no link', numero($tot['cliques_link'])], ['Visitas à página', numero($tot['visitas_lp'])], ['Checkouts (Meta)', numero($tot['checkouts'])],
        ['Compras (Meta)', numero($tot['compras'])], ['Custo por compra', $tot['compras'] ? reais((int)round($tot['gasto'] * 100 / $tot['compras'])) : '—']] as [$n, $val]): ?>
      <div class="card p-4"><p class="text-xs font-semibold text-navy/70"><?= $n ?></p><p class="font-display text-2xl text-navy mt-1"><?= $val ?></p></div>
    <?php endforeach; ?>
  </div>
  <div class="card p-4 text-sm text-navy/80">
    <strong>Kiwify no mesmo período:</strong> <?= numero($kiwPago['n']) ?> vendas pagas · <?= reais((int)$kiwPago['v']) ?>.
    <?php if ($tot['gasto'] > 0): ?> Retorno sobre o gasto (vendas Kiwify ÷ gasto Meta): <strong><?= numero($kiwPago['v'] / 100 / $tot['gasto'], 2) ?>x</strong>.<?php endif; ?>
    <span class="block text-xs text-navy/50 mt-1">*O alcance somado entre campanhas pode contar a mesma pessoa mais de uma vez. As vendas da Kiwify incluem as que não vieram de anúncio.</span>
  </div>

  <div class="card overflow-x-auto">
    <table class="t">
      <tr><th>Campanha</th><th>Situação</th><th class="n">Gasto</th><th class="n">Impressões</th><th class="n">Cliques no link</th><th class="n">CTR</th><th class="n">CPC</th><th class="n">Visitas à página</th><th class="n">Checkouts</th><th class="n">Compras</th><th class="n">Custo/compra</th></tr>
      <?php foreach ($linhas as $l): ?>
        <tr>
          <td class="font-semibold text-navy"><?= h($l['nome']) ?><span class="block text-xs font-normal text-navy/50"><?= h($l['conta']) ?></span></td>
          <td><?= h($statusPt[$l['status']] ?? $l['status']) ?></td>
          <td class="n"><?= reais((int)round($l['gasto'] * 100)) ?></td>
          <td class="n"><?= numero($l['impressoes']) ?></td>
          <td class="n"><?= numero($l['cliques_link']) ?></td>
          <td class="n"><?= numero($l['ctr'], 2) ?>%</td>
          <td class="n"><?= $l['cliques_link'] ? reais((int)round($l['cpc'] * 100)) : '—' ?></td>
          <td class="n"><?= numero($l['visitas_lp']) ?></td>
          <td class="n"><?= numero($l['checkouts']) ?></td>
          <td class="n"><?= numero($l['compras']) ?></td>
          <td class="n"><?= $l['compras'] ? reais((int)round($l['gasto'] * 100 / $l['compras'])) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$linhas && empty($m['erros']) && empty($m['erro'])): ?><tr><td colspan="11" class="text-navy/50">Nenhuma campanha com gasto neste período.</td></tr><?php endif; ?>
    </table>
  </div>

<?php elseif ($aba === 'vendas'):
    $produto = (string)($_GET['produto'] ?? '');
    $situacao = (string)($_GET['situacao'] ?? '');
    $filtro = " WHERE criado_em BETWEEN ? AND ?";
    $par = [$ini, $fim];
    if ($produto !== '') { $filtro .= " AND produto = ?"; $par[] = $produto; }
    if ($situacao !== '') { $filtro .= " AND situacao = ?"; $par[] = $situacao; }
    $vendas = q("SELECT * FROM vendas $filtro ORDER BY criado_em DESC LIMIT 1000", $par);
    $resumo = q("SELECT COUNT(CASE WHEN situacao='Pago' THEN 1 END) pagas, COALESCE(SUM(CASE WHEN situacao='Pago' THEN valor_centavos END),0) fat,
                 COALESCE(SUM(CASE WHEN situacao='Pago' THEN liquido_centavos END),0) liq, COUNT(CASE WHEN situacao='Aguardando pagamento' THEN 1 END) aguard,
                 COUNT(CASE WHEN situacao IN ('Reembolsado','Chargeback') THEN 1 END) reemb FROM vendas $filtro", $par)[0];
    $porDia = q("SELECT substr(criado_em,1,10) dia, COUNT(*) n, SUM(valor_centavos) v, SUM(liquido_centavos) l FROM vendas $filtro AND situacao='Pago' GROUP BY dia ORDER BY dia DESC", $par);
    $porProduto = q("SELECT produto, COUNT(*) n, SUM(valor_centavos) v FROM vendas $filtro AND situacao='Pago' GROUP BY produto ORDER BY v DESC", $par);
    $porOrigem = q("SELECT COALESCE(origem,'sem etiqueta') origem, COUNT(*) n, SUM(valor_centavos) v FROM vendas $filtro AND situacao='Pago' GROUP BY 1 ORDER BY v DESC", $par);
    $produtos = array_column(q("SELECT DISTINCT produto FROM vendas WHERE produto IS NOT NULL ORDER BY produto"), 'produto');
    $situacoes = array_column(q("SELECT DISTINCT situacao FROM vendas WHERE situacao IS NOT NULL ORDER BY situacao"), 'situacao');
?>
  <section class="flex flex-wrap items-end justify-between gap-3">
    <div>
      <h1 class="font-display text-3xl text-navy">Vendas (Kiwify)</h1>
      <p class="text-sm text-navy/60 mt-1">Todas as vendas de todos os produtos, recebidas automaticamente da Kiwify.</p>
    </div>
    <a class="btn2" href="exportar.php?<?= h(http_build_query(['de' => $de, 'ate' => $ate, 'produto' => $produto, 'situacao' => $situacao])) ?>">Baixar planilha</a>
  </section>

  <form method="get" class="card p-4 flex flex-wrap gap-3 items-end text-sm">
    <?php foreach (['aba' => 'vendas', 'per' => $per, 'de' => $de, 'ate' => $ate] as $k => $val): ?><input type="hidden" name="<?= $k ?>" value="<?= h($val) ?>"><?php endforeach; ?>
    <label class="grid gap-1 font-semibold text-navy">Produto
      <select name="produto"><option value="">Todos</option><?php foreach ($produtos as $x): ?><option <?= $x === $produto ? 'selected' : '' ?>><?= h($x) ?></option><?php endforeach; ?></select></label>
    <label class="grid gap-1 font-semibold text-navy">Situação
      <select name="situacao"><option value="">Todas</option><?php foreach ($situacoes as $x): ?><option <?= $x === $situacao ? 'selected' : '' ?>><?= h($x) ?></option><?php endforeach; ?></select></label>
    <button class="btn !py-2">Filtrar</button>
  </form>

  <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
    <?php foreach ([['Vendas pagas', numero($resumo['pagas'])], ['Faturamento', reais((int)$resumo['fat'])], ['Líquido (sua comissão)', $resumo['liq'] ? reais((int)$resumo['liq']) : '—'],
        ['Aguardando pagamento', numero($resumo['aguard'])], ['Reembolsos/chargebacks', numero($resumo['reemb'])]] as [$n, $val]): ?>
      <div class="card p-4"><p class="text-xs font-semibold text-navy/70"><?= $n ?></p><p class="font-display text-2xl text-navy mt-1"><?= $val ?></p></div>
    <?php endforeach; ?>
  </div>

  <div class="grid lg:grid-cols-3 gap-6">
    <div class="card p-4 overflow-x-auto"><h2 class="font-bold text-navy">Dia a dia (pagas)</h2>
      <table class="t mt-2"><tr><th>Dia</th><th class="n">Vendas</th><th class="n">Faturamento</th></tr>
        <?php foreach ($porDia as $d): ?><tr><td><?= date('d/m/Y', strtotime($d['dia'])) ?></td><td class="n"><?= numero($d['n']) ?></td><td class="n"><?= reais((int)$d['v']) ?></td></tr><?php endforeach; ?>
        <tr><td class="font-bold">Total</td><td class="n font-bold"><?= numero($resumo['pagas']) ?></td><td class="n font-bold"><?= reais((int)$resumo['fat']) ?></td></tr>
      </table></div>
    <div class="card p-4 overflow-x-auto"><h2 class="font-bold text-navy">Por produto (pagas)</h2>
      <table class="t mt-2"><tr><th>Produto</th><th class="n">Vendas</th><th class="n">Faturamento</th></tr>
        <?php foreach ($porProduto as $d): ?><tr><td><?= h($d['produto']) ?></td><td class="n"><?= numero($d['n']) ?></td><td class="n"><?= reais((int)$d['v']) ?></td></tr><?php endforeach; ?>
      </table></div>
    <div class="card p-4 overflow-x-auto"><h2 class="font-bold text-navy">Por origem (pagas)</h2>
      <table class="t mt-2"><tr><th>Origem</th><th class="n">Vendas</th><th class="n">Faturamento</th></tr>
        <?php foreach ($porOrigem as $d): ?><tr><td><?= h($d['origem']) ?></td><td class="n"><?= numero($d['n']) ?></td><td class="n"><?= reais((int)$d['v']) ?></td></tr><?php endforeach; ?>
      </table></div>
  </div>

  <div class="card overflow-x-auto">
    <table class="t">
      <tr><th>Data e hora</th><th>Produto</th><th class="n">Qtd.</th><th class="n">Valor</th><th>Nome</th><th>WhatsApp</th><th>E-mail</th><th>Pagamento</th><th>Situação</th><th>Origem</th></tr>
      <?php foreach ($vendas as $x):
          $wa = preg_replace('/\D/', '', (string)$x['whatsapp']);
          if ($wa !== '' && strlen($wa) <= 11) $wa = '55' . $wa; ?>
        <tr>
          <td class="whitespace-nowrap"><?= date('d/m/Y H:i', strtotime($x['criado_em'])) ?></td>
          <td><?= h($x['produto']) ?></td>
          <td class="n"><?= (int)$x['quantidade'] ?></td>
          <td class="n"><?= reais($x['valor_centavos'] !== null ? (int)$x['valor_centavos'] : null) ?></td>
          <td><?= h($x['nome']) ?></td>
          <td class="whitespace-nowrap"><?php if ($wa): ?><a class="text-leaf underline" href="https://wa.me/<?= h($wa) ?>" target="_blank" rel="noopener"><?= h($x['whatsapp']) ?></a><?php endif; ?></td>
          <td><?= h($x['email']) ?></td>
          <td><?= h($x['pagamento']) ?></td>
          <td><span class="rounded-full px-2 py-0.5 text-xs font-bold <?= $x['situacao'] === 'Pago' ? 'bg-leaf-50 text-leaf' : 'bg-navy/5 text-navy/70' ?>"><?= h($x['situacao']) ?></span></td>
          <td><?= h($x['origem'] ?? '') ?><?= $x['campanha'] ? ' · ' . h($x['campanha']) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$vendas): ?><tr><td colspan="10" class="text-navy/50">Nenhuma venda neste período.</td></tr><?php endif; ?>
    </table>
  </div>

<?php else: // ajustes
    $base = 'https://land.institutoformare.com.br/painel/';
    $ultimos = q("SELECT * FROM avisos_kiwify ORDER BY id DESC LIMIT 10");
?>
  <h1 class="font-display text-3xl text-navy">Ajustes</h1>
  <?php if (isset($_GET['novo'])): ?><div class="card p-4 bg-leaf-50 text-leaf font-semibold">Acesso criado. Agora faça as duas ligações abaixo: Kiwify e Meta.</div><?php endif; ?>
  <?php if ($aviso): ?><div class="card p-4 font-semibold text-navy"><?= h($aviso) ?></div><?php endif; ?>

  <section class="card p-5 sm:p-6">
    <h2 class="font-display text-2xl text-navy">1. Vendas da Kiwify</h2>
    <ol class="mt-3 text-sm text-navy/80 list-decimal pl-5 space-y-1">
      <li>Na Kiwify, vá em <strong>Apps → Webhooks → Criar webhook</strong>.</li>
      <li>Em <strong>URL</strong>, cole: <code class="bg-navy/5 rounded px-2 py-0.5 select-all"><?= h($base) ?>kiwify.php</code></li>
      <li>Em produtos, escolha <strong>todos os produtos</strong>. Em eventos, marque todos (compra aprovada, Pix gerado, reembolso, chargeback, compra recusada).</li>
      <li>A Kiwify mostra um <strong>token</strong>. Copie e cole aqui embaixo.</li>
    </ol>
    <form method="post" class="mt-4 flex flex-wrap gap-2 items-end">
      <input type="hidden" name="csrf" value="<?= h(token_form()) ?>"><input type="hidden" name="acao" value="kiwify">
      <label class="grid gap-1 text-sm font-semibold text-navy">Token do webhook da Kiwify
        <input name="kiwify_token" type="password" autocomplete="off" placeholder="<?= ajuste('kiwify_token') ? '•••••••• (já cadastrado)' : 'cole o token' ?>" class="w-72"></label>
      <button class="btn !py-2">Salvar</button>
    </form>
    <h3 class="font-bold text-navy mt-5 text-sm">Últimos avisos recebidos da Kiwify</h3>
    <table class="t mt-2"><tr><th>Quando</th><th>Pedido</th><th>Evento</th><th>Resultado</th></tr>
      <?php foreach ($ultimos as $a): ?><tr><td><?= date('d/m H:i', strtotime($a['recebido_em'])) ?></td><td><?= h($a['pedido']) ?></td><td><?= h($a['evento']) ?></td><td class="<?= $a['aceito'] ? 'text-leaf' : 'text-coral' ?>"><?= h($a['motivo']) ?></td></tr><?php endforeach; ?>
      <?php if (!$ultimos): ?><tr><td colspan="4" class="text-navy/50">Nenhum aviso ainda.</td></tr><?php endif; ?>
    </table>
  </section>

  <section class="card p-5 sm:p-6">
    <h2 class="font-display text-2xl text-navy">2. Campanhas da Meta</h2>
    <ol class="mt-3 text-sm text-navy/80 list-decimal pl-5 space-y-1">
      <li>No <strong>Gerenciador de Negócios</strong> (business.facebook.com) → <strong>Configurações do negócio</strong> → <strong>Usuários do sistema</strong>.</li>
      <li>Crie um usuário do sistema (função <em>Funcionário</em>) e dê a ele acesso às contas de anúncio, só com permissão de <strong>ver desempenho</strong>.</li>
      <li>Clique em <strong>Gerar novo token</strong>, marque a permissão <code>ads_read</code> e escolha validade <strong>Nunca</strong>.</li>
      <li>Cole o token aqui embaixo.</li>
    </ol>
    <form method="post" class="mt-4 grid sm:grid-cols-[1fr_1fr_auto] gap-2 items-end">
      <input type="hidden" name="csrf" value="<?= h(token_form()) ?>"><input type="hidden" name="acao" value="meta">
      <label class="grid gap-1 text-sm font-semibold text-navy">Token da Meta
        <input name="meta_token" type="password" autocomplete="off" placeholder="<?= ajuste('meta_token') ? '•••••••• (já cadastrado)' : 'cole o token' ?>"></label>
      <label class="grid gap-1 text-sm font-semibold text-navy">Contas de anúncio (números, separados por vírgula)
        <input name="meta_contas" value="<?= h(ajuste('meta_contas', META_CONTAS_PADRAO)) ?>"></label>
      <button class="btn !py-2">Salvar</button>
    </form>
  </section>

  <section class="card p-5 sm:p-6">
    <h2 class="font-display text-2xl text-navy">3. Trocar senha</h2>
    <form method="post" class="mt-4 flex flex-wrap gap-2 items-end">
      <input type="hidden" name="csrf" value="<?= h(token_form()) ?>"><input type="hidden" name="acao" value="senha">
      <label class="grid gap-1 text-sm font-semibold text-navy">Senha atual <input name="atual" type="password" autocomplete="current-password"></label>
      <label class="grid gap-1 text-sm font-semibold text-navy">Nova senha (mín. 10) <input name="nova" type="password" minlength="10" autocomplete="new-password"></label>
      <button class="btn !py-2">Trocar</button>
    </form>
  </section>
<?php endif; ?>
</main>
<?php rodape();
