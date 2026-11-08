<?php
// Painel Instituto Formare: landing pages, campanhas, vendas e ajustes.
require __DIR__ . '/lib.php';
require __DIR__ . '/meta.php';
require __DIR__ . '/kiwify_api.php';
exigir_login();

$abas = ['paginas' => 'Landing pages', 'links' => 'Links', 'campanhas' => 'Campanhas', 'vendas' => 'Vendas', 'ajustes' => 'Ajustes'];
$aba = $_GET['aba'] ?? 'paginas';
if (!isset($abas[$aba])) $aba = 'paginas';

// Período
$hoje = date('Y-m-d');
$atalhos = ['hoje' => [$hoje, $hoje], 'ontem' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
    '7d' => [date('Y-m-d', strtotime('-6 days')), $hoje], '30d' => [date('Y-m-d', strtotime('-29 days')), $hoje],
    'mes' => [date('Y-m-01'), $hoje], 'inicio' => [ajuste('inicio_campanhas', '2026-10-28'), $hoje]];
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
        db()->exec("DELETE FROM cache WHERE chave LIKE 'meta%'");
        $aviso = 'Dados da Meta salvos.';
    } elseif ($acao === 'kiwify_api') {
        foreach (['kiwify_client_id', 'kiwify_client_secret', 'kiwify_account_id'] as $k) {
            if (trim((string)($_POST[$k] ?? '')) !== '') salvar_ajuste($k, trim((string)$_POST[$k]));
        }
        salvar_ajuste('kiwify_api_token', null);
        $aviso = 'Chave de API da Kiwify salva. Agora clique em "Importar vendas".';
    } elseif ($acao === 'importar') {
        $desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['desde'] ?? '')) ? $_POST['desde'] : '2026-01-01';
        $res = importar_kiwify($desde);
        $aviso = isset($res['erro']) ? $res['erro'] . (!empty($res['importadas']) ? ' (' . $res['importadas'] . ' vendas importadas antes do erro.)' : '')
            : 'Importação concluída: ' . $res['importadas'] . ' registros de venda lidos da Kiwify.';
    } elseif ($acao === 'metas') {
        $cm = str_replace(',', '.', preg_replace('/[^0-9,.]/', '', (string)($_POST['custo_max'] ?? '')));
        if ($cm !== '' && (float)$cm > 0) salvar_ajuste('custo_max', (string)(float)$cm);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['inicio'] ?? ''))) salvar_ajuste('inicio_campanhas', $_POST['inicio']);
        $aviso = 'Metas salvas.';
    } elseif ($acao === 'senha') {
        $atual = (string)($_POST['atual'] ?? '');
        $nova = (string)($_POST['nova'] ?? '');
        if (!password_verify($atual, (string)ajuste('senha_hash'))) $aviso = 'A senha atual está incorreta.';
        elseif (mb_strlen($nova) < 10) $aviso = 'A nova senha precisa ter pelo menos 10 caracteres.';
        else { salvar_ajuste('senha_hash', password_hash($nova, PASSWORD_DEFAULT)); $aviso = 'Senha trocada.'; }
    }
}

// Links: guarda cada link gerado (chamado pelo botão "Gerar e copiar")
if ($aba === 'links' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    conferir_form();
    $limpa = fn($v, $n) => mb_substr(preg_replace('/[^a-z0-9\-]/', '', strtolower((string)$v)), 0, $n);
    if (($_POST['acao'] ?? '') === 'apagar') {
        db()->prepare('DELETE FROM links WHERE id = ?')->execute([(int)$_POST['id']]);
    } else {
        $pagina = $limpa($_POST['pagina'] ?? '', 60);
        $origem = $limpa($_POST['origem'] ?? '', 40);
        $campanha = $limpa($_POST['campanha'] ?? '', 40);
        if ($pagina !== '' && $origem !== '') {
            $url = 'https://land.institutoformare.com.br/' . $pagina . '/?o=' . $origem . ($campanha !== '' ? '&c=' . $campanha : '');
            db()->prepare('INSERT INTO links (criado_em, pagina, origem, campanha, url, nota) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([date('Y-m-d H:i:s'), $pagina, $origem, $campanha ?: null, $url, mb_substr(trim((string)($_POST['nota'] ?? '')), 0, 120) ?: null]);
        }
    }
    if (isset($_POST['ajax'])) { header('Content-Type: application/json'); echo '{"ok":true}'; exit; }
    header('Location: ?aba=links');
    exit;
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

<?php if ($aba !== 'ajustes' && $aba !== 'links'): ?>
  <form class="card p-4 flex flex-wrap items-end gap-3" method="get">
    <input type="hidden" name="aba" value="<?= h($aba) ?>">
    <div class="flex flex-wrap gap-2">
      <?php foreach (['hoje' => 'Hoje', 'ontem' => 'Ontem', '7d' => '7 dias', '30d' => '30 dias', 'mes' => 'Este mês', 'inicio' => 'Desde o início'] as $k => $n): ?>
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

<?php elseif ($aba === 'links'):
    $paginas = ['livro-licitacoes' => 'Livro Digital Completo · Licitações', 'livro-servidor-4-0' => 'Livro Digital Completo · Servidor 4.0'];
    foreach (q("SELECT DISTINCT pagina FROM eventos") as $pg) {
        $slug = trim((string)$pg['pagina'], '/');
        if ($slug !== '' && !str_contains($slug, '/') && !isset($paginas[$slug]) && $slug !== 'cartilha-licitacoes') $paginas[$slug] = $slug;
    }
    $origens = ['whatsapp' => 'WhatsApp', 'instagram' => 'Instagram (post ou geral)', 'instagram-direct' => 'Instagram Direct / ManyChat', 'instagram-stories' => 'Instagram Stories',
        'instagram-bio' => 'Bio do Instagram', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', 'facebook' => 'Facebook', 'linkedin' => 'LinkedIn', 'email' => 'E-mail', 'telegram' => 'Telegram'];
    $gerados = q("SELECT l.*,
        (SELECT COUNT(DISTINCT e.sessao) FROM eventos e WHERE e.tipo='visita' AND e.origem = l.origem AND COALESCE(e.campanha,'') = COALESCE(l.campanha,'') AND trim(e.pagina,'/') = l.pagina) visitas,
        (SELECT COUNT(DISTINCT v.pedido) FROM vendas v WHERE v.situacao='Pago' AND v.origem = l.origem AND COALESCE(v.campanha,'') = COALESCE(l.campanha,'')) vendas
        FROM links l ORDER BY l.id DESC LIMIT 200");
?>
  <section>
    <h1 class="font-display text-3xl text-navy">Links</h1>
    <p class="text-sm text-navy/60 mt-1">Escolha a página e onde vai divulgar. O painel monta o link e já copia: é só colar no WhatsApp, no ManyChat, na bio ou onde for. Quem comprar por esse link aparece com essa origem na aba Vendas.</p>
  </section>

  <div class="card p-5 sm:p-6">
    <form id="form-link" class="grid md:grid-cols-[1.2fr_1fr_1fr] gap-4 items-end">
      <input type="hidden" name="csrf" value="<?= h(token_form()) ?>">
      <label class="grid gap-1 text-sm font-semibold text-navy">Landing page
        <select name="pagina"><?php foreach ($paginas as $k => $n): ?><option value="<?= h($k) ?>"><?= h($n) ?></option><?php endforeach; ?></select></label>
      <label class="grid gap-1 text-sm font-semibold text-navy">Onde vai divulgar
        <select name="origem" id="origem"><?php foreach ($origens as $k => $n): ?><option value="<?= h($k) ?>"><?= h($n) ?></option><?php endforeach; ?><option value="outro">Outro (escrever)</option></select></label>
      <label class="grid gap-1 text-sm font-semibold text-navy" id="outro-box" style="display:none">Qual?
        <input name="outro" placeholder="ex.: grupo-prefeitos, palestra-sp"></label>
      <label class="grid gap-1 text-sm font-semibold text-navy md:col-span-1">Campanha ou post <span class="font-normal text-navy/50">(opcional)</span>
        <input name="campanha" placeholder="ex.: post-etp, amigo-joao"></label>
      <label class="grid gap-1 text-sm font-semibold text-navy md:col-span-2">Anotação para você <span class="font-normal text-navy/50">(opcional, não vai no link)</span>
        <input name="nota" placeholder="ex.: arte do Instagram de segunda, resposta automática do ManyChat"></label>
      <button type="submit" class="btn justify-center md:col-span-3 !py-3 !text-lg">Gerar e copiar link</button>
    </form>

    <div id="resultado" class="mt-5 hidden">
      <label class="text-sm font-semibold text-navy">Seu link</label>
      <div class="mt-1 flex flex-col sm:flex-row gap-2">
        <input id="link-gerado" readonly class="flex-1 font-mono text-sm !bg-mint/40" onclick="this.select()">
        <button type="button" id="copiar" class="btn2 justify-center">Copiar de novo</button>
      </div>
      <p id="copiado" class="mt-2 text-sm font-semibold text-leaf">✓ Link copiado. É só colar.</p>
    </div>
  </div>

  <div class="card overflow-x-auto">
    <h2 class="font-bold text-navy px-4 pt-4">Links gerados</h2>
    <p class="text-xs text-navy/50 px-4">Visitas e vendas contam todas as pessoas que chegaram com a mesma origem e campanha.</p>
    <table class="t mt-2">
      <tr><th>Criado em</th><th>Página</th><th>Origem</th><th>Campanha</th><th>Anotação</th><th class="n">Visitas</th><th class="n">Vendas</th><th>Link</th><th></th></tr>
      <?php foreach ($gerados as $l): ?>
        <tr>
          <td class="whitespace-nowrap"><?= date('d/m/Y H:i', strtotime($l['criado_em'])) ?></td>
          <td><?= h($paginas[$l['pagina']] ?? $l['pagina']) ?></td>
          <td><?= h($origens[$l['origem']] ?? $l['origem']) ?></td>
          <td><?= h($l['campanha']) ?></td>
          <td class="text-xs"><?= h($l['nota']) ?></td>
          <td class="n"><?= numero($l['visitas']) ?></td>
          <td class="n"><?= numero($l['vendas']) ?></td>
          <td><button type="button" class="btn2 !py-1 !px-3 text-xs js-copiar" data-url="<?= h($l['url']) ?>">Copiar</button></td>
          <td><form method="post" onsubmit="return confirm('Apagar este link da lista? (o link continua funcionando)')">
            <input type="hidden" name="csrf" value="<?= h(token_form()) ?>"><input type="hidden" name="acao" value="apagar"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <button class="text-xs text-navy/40 underline">apagar</button></form></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$gerados): ?><tr><td colspan="9" class="text-navy/50">Nenhum link gerado ainda.</td></tr><?php endif; ?>
    </table>
  </div>

  <script>
  (function () {
    var f = document.getElementById('form-link'), sel = document.getElementById('origem'), box = document.getElementById('outro-box');
    function slug(s) { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40); }
    function copiar(t) {
      if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(t);
      var i = document.createElement('textarea'); i.value = t; document.body.appendChild(i); i.select(); document.execCommand('copy'); i.remove(); return Promise.resolve();
    }
    sel.addEventListener('change', function () { box.style.display = sel.value === 'outro' ? 'grid' : 'none'; });
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var origem = sel.value === 'outro' ? slug(f.outro.value) : sel.value;
      if (!origem) { alert('Escreva onde vai divulgar.'); return; }
      var camp = slug(f.campanha.value);
      var url = 'https://land.institutoformare.com.br/' + f.pagina.value + '/?o=' + origem + (camp ? '&c=' + camp : '');
      document.getElementById('resultado').classList.remove('hidden');
      var campo = document.getElementById('link-gerado'); campo.value = url;
      copiar(url).then(function () { document.getElementById('copiado').textContent = '✓ Link copiado. É só colar.'; },
                       function () { campo.select(); document.getElementById('copiado').textContent = 'Selecione e copie o link acima (Ctrl + C).'; });
      var d = new FormData(); d.append('csrf', f.csrf.value); d.append('pagina', f.pagina.value); d.append('origem', origem);
      d.append('campanha', camp); d.append('nota', f.nota.value); d.append('ajax', '1');
      fetch('?aba=links', { method: 'POST', body: d, credentials: 'same-origin' });
    });
    document.getElementById('copiar').addEventListener('click', function () { copiar(document.getElementById('link-gerado').value); document.getElementById('copiado').textContent = '✓ Copiado de novo.'; });
    document.querySelectorAll('.js-copiar').forEach(function (b) {
      b.addEventListener('click', function () { copiar(b.dataset.url).then(function () { b.textContent = '✓ Copiado'; setTimeout(function () { b.textContent = 'Copiar'; }, 1500); }); });
    });
  })();
  </script>

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
  <?php
    $custoMax = (float)ajuste('custo_max', '85');
    $kiw = q("SELECT COUNT(DISTINCT CASE WHEN situacao='Pago' THEN pedido END) pedidos, COUNT(CASE WHEN situacao='Pago' THEN 1 END) itens,
              COALESCE(SUM(CASE WHEN situacao='Pago' THEN valor_centavos END),0) bruto, COALESCE(SUM(CASE WHEN situacao='Pago' THEN liquido_centavos END),0) liquido,
              COUNT(CASE WHEN situacao='Pago' AND liquido_centavos IS NULL THEN 1 END) sem_liquido,
              COUNT(CASE WHEN situacao='Aguardando pagamento' THEN 1 END) aguardando
              FROM vendas WHERE criado_em BETWEEN ? AND ?", [$ini, $fim])[0];
    $kiwDesde = q("SELECT MIN(recebido_em) d FROM avisos_kiwify WHERE aceito = 1")[0]['d'];
    // Só usa a Kiwify como fonte quando ela cobre o período inteiro; senão usa as compras do Pixel.
    $temKiwify = $kiwDesde && substr($kiwDesde, 0, 10) <= $de;
    $ticket = q("SELECT AVG(t) m FROM (SELECT SUM(liquido_centavos) t FROM vendas WHERE situacao='Pago' AND liquido_centavos IS NOT NULL GROUP BY pedido)")[0]['m'];
    $ticketLiq = $ticket ? $ticket / 100 : $custoMax;
    $vendasPagas = $temKiwify ? (int)$kiw['pedidos'] : (int)$tot['compras'];
    $liquido = $temKiwify ? $kiw['liquido'] / 100 : $tot['compras'] * $ticketLiq;
    $g = $tot['gasto'];
    $met = [
        'cpm' => $tot['impressoes'] ? $g / $tot['impressoes'] * 1000 : null,
        'ctr' => $tot['impressoes'] ? $tot['cliques_link'] / $tot['impressoes'] * 100 : null,
        'cpc' => $tot['cliques_link'] ? $g / $tot['cliques_link'] : null,
        'fin' => $tot['checkouts'] ? $vendasPagas / $tot['checkouts'] * 100 : null,
        'cpv' => $vendasPagas ? $g / $vendasPagas : null,
        'roas' => $g > 0 ? $liquido / $g : null,
    ];
    // Faixas (iguais às da skill painel-metricas-trafego-tatai). Custo por venda segue o custo máximo cadastrado.
    $faixas = [
        'cpm' => ['CPM', 'gasto ÷ impressões × 1000', 'menor', [40, 25, 15], 'R$', 'Preço do leilão para o seu público. Sobe sozinho em época concorrida (feriado, fim de ano), o que não significa que o anúncio piorou.'],
        'ctr' => ['CTR do link', 'cliques no link ÷ impressões', 'maior', [0.8, 1.2, 1.8], '%', 'Campanha de Vendas tem CTR naturalmente mais baixo que Tráfego ou Engajamento. Não compare com esses objetivos.'],
        'cpc' => ['CPC do link', 'gasto ÷ cliques no link', 'menor', [7, 4, 2], 'R$', 'É consequência do CPM e do CTR. Se estiver ruim, veja qual dos dois está puxando.'],
        'fin' => ['Finalização do checkout', 'vendas pagas ÷ checkouts iniciados', 'maior', [15, 30, 45], '%', 'Queda forte aqui é problema de pagamento (Pix não gera, cartão recusa) ou de preço/oferta, não de anúncio.'],
        'cpv' => ['Custo por venda', 'gasto ÷ vendas pagas', 'menor', [$custoMax, $custoMax * 0.765, $custoMax * 0.59], 'R$', 'Calculado sobre o valor líquido. Acima de ' . reais((int)round($custoMax * 100)) . ' a venda sai no prejuízo.'],
        'roas' => ['ROAS líquido', 'receita líquida ÷ gasto', 'maior', [1.0, 1.3, 1.8], 'x', 'Usa o líquido real da Kiwify, somando as ofertas extras do checkout (que não aparecem no ROAS do Pixel).'],
    ];
    function fmt_m($v, $u) { return $u === 'R$' ? reais((int)round($v * 100)) : ($u === '%' ? numero($v, 2) . '%' : numero($v, 2)); }
    function faixa_de($v, $sentido, $l) {
        if ($v === null) return -1;
        if ($sentido === 'menor') return $v > $l[0] ? 0 : ($v > $l[1] ? 1 : ($v > $l[2] ? 2 : 3));
        return $v < $l[0] ? 0 : ($v < $l[1] ? 1 : ($v < $l[2] ? 2 : 3));
    }
    // Recomendação do dia (regras da skill)
    $anuncios = $m['anuncios'] ?? [];
    $comVenda = array_filter($anuncios, fn($a) => $a['compras'] > 0);
    $fracos = array_filter($anuncios, fn($a) => $a['compras'] === 0 && $a['gasto'] >= $custoMax);
    $alertas = $m['alertas'] ?? [];
    if ($alertas) { $pos = ['Alerta', 'Há problema na conta ou em anúncios. Veja os alertas abaixo.', 'coral']; }
    elseif ($g >= 2 * $custoMax && $vendasPagas === 0) { $pos = ['Ajustar', 'Já foram ' . reais((int)round($g * 100)) . ' sem nenhuma venda. Revise criativos e checkout.', 'coral']; }
    elseif ($fracos && $comVenda) { $pos = ['Pausar algo', 'O anúncio "' . reset($fracos)['nome'] . '" gastou mais que o custo máximo sem vender, enquanto outro vende. Avalie pausar.', 'sun']; }
    elseif ($vendasPagas > 0 && $met['cpv'] !== null && $met['cpv'] < $custoMax) { $pos = ['Manter', 'Custo por venda abaixo do máximo. Se ficar estável por 3 a 5 dias, considere subir 20% do orçamento.', 'leaf']; }
    elseif ($g > 0) { $pos = ['Manter', 'Nada a fazer agora: deixe a campanha aprender.', 'leaf']; }
    else { $pos = ['Sem gasto', 'Nenhum gasto de anúncio neste período.', 'navy']; }
    $pequena = $vendasPagas < 5;
    $cores = ['coral' => 'bg-coral text-white', 'sun' => 'bg-sun text-navy', 'leaf' => 'bg-leaf text-white', 'navy' => 'bg-navy text-white'];
  ?>
  <div class="card p-5 flex flex-wrap items-center gap-4">
    <span class="rounded-full px-4 py-2 font-display text-xl <?= $cores[$pos[2]] ?>"><?= h($pos[0]) ?></span>
    <div class="flex-1 min-w-[240px]">
      <p class="font-semibold text-navy"><?= h($pos[1]) ?></p>
      <?php if ($pequena && $g > 0): ?><p class="text-xs text-navy/60 mt-1">Amostra pequena (menos de 5 vendas): não tire conclusões fortes ainda.</p><?php endif; ?>
    </div>
    <button type="button" onclick="window.print()" class="btn2 no-print">Imprimir / salvar PDF</button>
  </div>
  <?php foreach ($alertas as $al): ?><div class="card p-4 text-coral font-semibold">⚠ <?= h($al) ?></div><?php endforeach; ?>
  <?php if (!$temKiwify): ?><div class="card p-4 text-sm text-[#a0680e] bg-[#fdf3e2]"><?= $kiwDesde ? 'O painel recebe as vendas da Kiwify desde ' . date('d/m/Y', strtotime($kiwDesde)) . ', que é depois do início deste período.' : 'O painel ainda não recebeu vendas da Kiwify.' ?> Por isso, vendas, receita, custo por venda e ROAS abaixo usam as <strong>compras registradas pelo Pixel</strong>, com receita estimada em <?= reais((int)round($ticketLiq * 100)) ?> líquidos por venda<?= $ticket ? ' (média real das vendas da Kiwify)' : ' (custo máximo, pois ainda não há média real)' ?>. Para números 100% da Kiwify, escolha um período a partir de <?= $kiwDesde ? date('d/m/Y', strtotime($kiwDesde)) : 'quando o webhook começar a receber vendas' ?>.</div><?php endif; ?>

  <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
    <?php foreach ([['Vendas pagas' . ($temKiwify ? ' (Kiwify)' : ' (Pixel)'), numero($vendasPagas)], ['Receita líquida', reais((int)round($liquido * 100))],
        ['Lucro estimado (líquido − gasto)', reais((int)round(($liquido - $g) * 100))], ['Aguardando pagamento', numero($kiw['aguardando'])]] as [$n, $val]): ?>
      <div class="card p-4"><p class="text-xs font-semibold text-navy/70"><?= $n ?></p><p class="font-display text-2xl <?= str_starts_with($n, 'Lucro') && $liquido - $g < 0 ? 'text-coral' : 'text-navy' ?> mt-1"><?= $val ?></p></div>
    <?php endforeach; ?>
  </div>

  <div class="card overflow-x-auto">
    <table class="t faixas">
      <tr><th>Métrica</th><th style="background:#c0392b;color:#fff">Ruim</th><th style="background:#d68910;color:#fff">Médio</th><th style="background:#2e8b57;color:#fff">Bom</th><th style="background:#1a6b3c;color:#fff">Ótimo</th><th>O que olhar</th></tr>
      <?php foreach ($faixas as $k => [$nome, $sub, $sentido, $l, $u, $obs]):
          $v = $met[$k]; $f = faixa_de($v, $sentido, $l);
          $tx = $sentido === 'menor'
              ? ['acima de ' . fmt_m($l[0], $u), fmt_m($l[1], $u) . ' – ' . fmt_m($l[0], $u), fmt_m($l[2], $u) . ' – ' . fmt_m($l[1], $u), 'abaixo de ' . fmt_m($l[2], $u)]
              : ['abaixo de ' . fmt_m($l[0], $u), fmt_m($l[0], $u) . ' – ' . fmt_m($l[1], $u), fmt_m($l[1], $u) . ' – ' . fmt_m($l[2], $u), 'acima de ' . fmt_m($l[2], $u)];
          $bg = ['bg-[#fdecea] text-[#a33025]', 'bg-[#fdf3e2] text-[#a0680e]', 'bg-[#e9f6ee] text-[#1f7a45]', 'bg-[#dcefe3] text-[#11532f]']; ?>
        <tr>
          <td class="font-bold text-navy"><?= $nome ?><span class="block text-xs font-normal text-navy/50"><?= $sub ?></span></td>
          <?php for ($i = 0; $i < 4; $i++): ?>
            <td class="<?= $bg[$i] ?> font-semibold"><?= $tx[$i] ?><?php if ($f === $i): ?><span class="block mt-1 rounded-md border-2 border-current px-2 py-0.5 font-extrabold">Seu: <?= fmt_m($v, $u) ?></span><?php endif; ?></td>
          <?php endfor; ?>
          <td class="text-xs text-navy/70"><?= $v === null ? '<strong>sem dado no período.</strong> ' : '' ?><?= h($obs) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="px-4 py-3 text-xs text-navy/50">Fonte das vendas: <?= $temKiwify ? 'vendas confirmadas na Kiwify (pedidos pagos; o líquido inclui as ofertas extras do checkout)' . ($kiw['sem_liquido'] ? '. Atenção: ' . $kiw['sem_liquido'] . ' venda(s) sem valor líquido informado' : '') : 'compras do Pixel (a Kiwify não cobre o período todo); receita estimada pelo líquido médio por venda' ?>. CPM, CTR, CPC e finalização são referências de mercado aproximadas.</p>
  </div>

  <div class="card p-4 text-sm text-navy/80">
    <strong>Conferência Kiwify x Pixel:</strong>
    <?php if (!$temKiwify): ?>a Kiwify não cobre o período inteiro, então não dá para comparar. Escolha um período a partir de <?= $kiwDesde ? date('d/m/Y', strtotime($kiwDesde)) : 'quando a primeira venda chegar' ?>.
    <?php elseif ((int)$kiw['pedidos'] === (int)$tot['compras']): ?>bateu: <?= numero($kiw['pedidos']) ?> vendas na Kiwify e <?= numero($tot['compras']) ?> compras atribuídas aos anúncios.
    <?php else: ?><?= numero($kiw['pedidos']) ?> vendas pagas na Kiwify e <?= numero($tot['compras']) ?> compras atribuídas aos anúncios pela Meta. A diferença inclui vendas que não vieram de anúncio e compras que o Pixel não registrou (cartão costuma falhar mais que Pix).<?php endif; ?>
  </div>

  <?php if ($anuncios): ?>
  <div class="card overflow-x-auto">
    <h2 class="font-bold text-navy px-4 pt-4">Ranking dos anúncios</h2>
    <table class="t mt-2">
      <tr><th>Anúncio</th><th class="n">Gasto</th><th class="n">CTR</th><th class="n">Compras (Meta)</th><th class="n">Custo/compra</th><th>Leitura</th></tr>
      <?php foreach ($anuncios as $an):
          $ctrA = $an['impressoes'] ? $an['cliques_link'] / $an['impressoes'] * 100 : 0;
          $cpa = $an['compras'] ? $an['gasto'] / $an['compras'] : null;
          $leitura = $an['compras'] ? ($cpa <= $custoMax ? 'Vendendo dentro do custo máximo.' : 'Vende, mas acima do custo máximo.') : ($an['gasto'] >= $custoMax ? 'Gastou o custo máximo sem vender.' : 'Ainda sem venda; amostra pequena.'); ?>
        <tr><td class="font-semibold text-navy"><?= h($an['nome']) ?><span class="block text-xs font-normal text-navy/50"><?= h($an['campanha']) ?></span></td>
          <td class="n"><?= reais((int)round($an['gasto'] * 100)) ?></td><td class="n"><?= numero($ctrA, 2) ?>%</td><td class="n"><?= numero($an['compras']) ?></td>
          <td class="n"><?= $cpa !== null ? reais((int)round($cpa * 100)) : '—' ?></td><td class="text-xs"><?= $leitura ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>

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
  <?php
    $pend = is_file(__DIR__ . '/PENDENCIAS.md') ? array_filter(file(__DIR__ . '/PENDENCIAS.md'), fn($l) => str_starts_with(trim($l), '- [ ]')) : [];
    if ($pend): ?>
    <section class="card p-5 sm:p-6 bg-[#fdf3e2]">
      <h2 class="font-display text-2xl text-navy">Lembretes</h2>
      <ul class="mt-3 space-y-2 text-sm text-navy/85 list-disc pl-5">
        <?php foreach ($pend as $l): ?><li><?= h(trim(substr(trim($l), 5))) ?></li><?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>
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
      <label class="grid gap-1 text-sm font-semibold text-navy w-full sm:w-auto">Token do webhook da Kiwify
        <input name="kiwify_token" type="password" autocomplete="off" placeholder="<?= ajuste('kiwify_token') ? '•••••••• (já cadastrado)' : 'cole o token' ?>" class="w-full sm:w-80"></label>
      <button class="btn !py-2">Salvar</button>
    </form>
    <h3 class="font-bold text-navy mt-5 text-sm">Últimos avisos recebidos da Kiwify</h3>
    <div class="overflow-x-auto"><table class="t mt-2"><tr><th>Quando</th><th>Pedido</th><th>Evento</th><th>Resultado</th></tr>
      <?php foreach ($ultimos as $a): ?><tr><td><?= date('d/m H:i', strtotime($a['recebido_em'])) ?></td><td><?= h($a['pedido']) ?></td><td><?= h($a['evento']) ?></td><td class="<?= $a['aceito'] ? 'text-leaf' : (str_starts_with((string)$a['motivo'], 'Aviso sem número') ? 'text-navy/50' : 'text-coral') ?>"><?= h($a['motivo']) ?></td></tr><?php endforeach; ?>
      <?php if (!$ultimos): ?><tr><td colspan="4" class="text-navy/50">Nenhum aviso ainda.</td></tr><?php endif; ?>
    </table></div>
  </section>

  <section class="card p-5 sm:p-6">
    <h2 class="font-display text-2xl text-navy">1b. Histórico de vendas da Kiwify</h2>
    <p class="mt-2 text-sm text-navy/70">Traz as vendas antigas, de antes do webhook. Na Kiwify: <strong>Apps → API → Criar API Key</strong>, marcando só <strong>Vendas</strong> (pode desmarcar o resto). Cole os três dados abaixo.</p>
    <form method="post" class="mt-4 grid sm:grid-cols-[1fr_1fr_1fr_auto] gap-2 items-end">
      <input type="hidden" name="csrf" value="<?= h(token_form()) ?>"><input type="hidden" name="acao" value="kiwify_api">
      <?php foreach (['kiwify_client_id' => 'Client ID', 'kiwify_client_secret' => 'Client Secret', 'kiwify_account_id' => 'Account ID'] as $k => $n): ?>
        <label class="grid gap-1 text-sm font-semibold text-navy w-full sm:w-auto"><?= $n ?>
          <input name="<?= $k ?>" type="password" autocomplete="off" placeholder="<?= ajuste($k) ? '•••••••• (já cadastrado)' : 'cole aqui' ?>"></label>
      <?php endforeach; ?>
      <button class="btn !py-2">Salvar</button>
    </form>
    <?php if (ajuste('kiwify_client_id') && ajuste('kiwify_client_secret') && ajuste('kiwify_account_id')): ?>
    <form method="post" class="mt-4 flex flex-wrap gap-2 items-end" onsubmit="this.querySelector('button').textContent='Importando… aguarde';">
      <input type="hidden" name="csrf" value="<?= h(token_form()) ?>"><input type="hidden" name="acao" value="importar">
      <label class="grid gap-1 text-sm font-semibold text-navy w-full sm:w-auto">Importar vendas desde
        <input type="date" name="desde" value="2026-01-01"></label>
      <button class="btn !py-2">Importar vendas</button>
      <?php if (ajuste('kiwify_importado_em')): ?><span class="text-xs text-navy/60">Última importação: <?= date('d/m/Y H:i', strtotime(ajuste('kiwify_importado_em'))) ?><?= ajuste('kiwify_importado_qtd') !== null ? ' · ' . h(ajuste('kiwify_importado_qtd')) . ' registros lidos' : '' ?> · no painel agora: <?= numero(q("SELECT COUNT(*) n FROM vendas")[0]['n']) ?> registros de venda</span><?php endif; ?>
    </form>
    <?php endif; ?>
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
      <label class="grid gap-1 text-sm font-semibold text-navy w-full sm:w-auto">Token da Meta
        <input name="meta_token" type="password" autocomplete="off" placeholder="<?= ajuste('meta_token') ? '•••••••• (já cadastrado)' : 'cole o token' ?>"></label>
      <label class="grid gap-1 text-sm font-semibold text-navy w-full sm:w-auto">Contas de anúncio (números, separados por vírgula)
        <input name="meta_contas" value="<?= h(ajuste('meta_contas', META_CONTAS_PADRAO)) ?>"></label>
      <button class="btn !py-2">Salvar</button>
    </form>
  </section>

  <section class="card p-5 sm:p-6">
    <h2 class="font-display text-2xl text-navy">3. Metas do tráfego</h2>
    <p class="mt-2 text-sm text-navy/70">Usadas na aba Campanhas para pintar as faixas e dar a recomendação do dia.</p>
    <form method="post" class="mt-4 flex flex-wrap gap-2 items-end">
      <input type="hidden" name="csrf" value="<?= h(token_form()) ?>"><input type="hidden" name="acao" value="metas">
      <label class="grid gap-1 text-sm font-semibold text-navy w-full sm:w-auto">Custo máximo por venda (R$)
        <input name="custo_max" inputmode="decimal" value="<?= h(numero((float)ajuste('custo_max', '85'), 2)) ?>"></label>
      <label class="grid gap-1 text-sm font-semibold text-navy w-full sm:w-auto">Início das campanhas ("Desde o início")
        <input name="inicio" type="date" value="<?= h(ajuste('inicio_campanhas', '2026-10-28')) ?>"></label>
      <button class="btn !py-2">Salvar</button>
    </form>
  </section>

  <section class="card p-5 sm:p-6">
    <h2 class="font-display text-2xl text-navy">4. Trocar senha</h2>
    <form method="post" class="mt-4 flex flex-wrap gap-2 items-end">
      <input type="hidden" name="csrf" value="<?= h(token_form()) ?>"><input type="hidden" name="acao" value="senha">
      <label class="grid gap-1 text-sm font-semibold text-navy w-full sm:w-auto">Senha atual <input name="atual" type="password" autocomplete="current-password"></label>
      <label class="grid gap-1 text-sm font-semibold text-navy w-full sm:w-auto">Nova senha (mín. 10) <input name="nova" type="password" minlength="10" autocomplete="new-password"></label>
      <button class="btn !py-2">Trocar</button>
    </form>
  </section>
<?php endif; ?>
</main>
<?php rodape();
