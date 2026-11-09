<?php
// Tela de login do painel.
require __DIR__ . '/lib.php';
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');

if (ajuste('senha_hash') === null) {
    header('Location: configurar.php');
    exit;
}
if (logado()) {
    header('Location: ./');
    exit;
}

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    conferir_form();
    $usuario = trim((string)($_POST['usuario'] ?? ''));
    $senha = (string)($_POST['senha'] ?? '');
    if (hash_equals((string)ajuste('usuario'), $usuario) && password_verify($senha, (string)ajuste('senha_hash'))) {
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        $_SESSION['ate'] = time() + 8 * 3600;
        header('Location: ./');
        exit;
    }
    registrar_tentativa();
    $erro = 'Usuário ou senha incorretos.';
}

cabecalho('Entrar');
?>
<main class="max-w-md mx-auto px-4 py-12">
  <img src="../livro-licitacoes/img/logo-formare.webp" alt="Instituto Formare" class="h-11 w-auto">
  <div class="card p-6 sm:p-8 mt-6">
    <h1 class="font-display text-3xl text-navy">Painel</h1>
    <p class="mt-2 text-sm text-navy/70">Landing pages, campanhas e vendas.</p>
    <?php if ($erro): ?><p class="mt-4 rounded-xl bg-coral/10 text-coral font-semibold px-4 py-3 text-sm"><?= h($erro) ?></p><?php endif; ?>
    <form method="post" class="mt-5 grid gap-4">
      <input type="hidden" name="csrf" value="<?= h(token_form()) ?>">
      <label class="grid gap-1 text-sm font-semibold text-navy">Usuário
        <input name="usuario" required autocomplete="username"></label>
      <label class="grid gap-1 text-sm font-semibold text-navy">Senha
        <input name="senha" type="password" required autocomplete="current-password"></label>
      <button class="btn justify-center mt-2">Entrar</button>
    </form>
    <p class="mt-4 text-sm text-center"><a class="text-navy/70 underline" href="esqueci.php">Esqueci o usuário ou a senha</a></p>
  </div>
</main>
<?php rodape();
