<?php
// Link do e-mail: cria uma nova senha (o link vale por 1 hora e uma única vez).
require __DIR__ . '/lib.php';
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$t = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$valido = $t !== '' && ajuste('reset_hash') !== null && (int)ajuste('reset_ate', '0') > time()
    && hash_equals((string)ajuste('reset_hash'), hash('sha256', $t));

$erro = '';
if ($valido && $_SERVER['REQUEST_METHOD'] === 'POST') {
    conferir_form();
    $usuario = trim((string)($_POST['usuario'] ?? ''));
    $senha = (string)($_POST['senha'] ?? '');
    if (mb_strlen($usuario) < 3) $erro = 'O usuário precisa ter pelo menos 3 letras.';
    elseif (mb_strlen($senha) < 10) $erro = 'A senha precisa ter pelo menos 10 caracteres.';
    elseif ($senha !== (string)($_POST['senha2'] ?? '')) $erro = 'As duas senhas não são iguais.';
    else {
        salvar_ajuste('usuario', $usuario);
        salvar_ajuste('senha_hash', password_hash($senha, PASSWORD_DEFAULT));
        salvar_ajuste('reset_hash', null);
        salvar_ajuste('reset_ate', null);
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        $_SESSION['ate'] = time() + 8 * 3600;
        header('Location: ./');
        exit;
    }
}

cabecalho('Nova senha');
?>
<main class="max-w-md mx-auto px-4 py-12">
  <img src="../livro-licitacoes/img/logo-formare.webp" alt="Instituto Formare" class="h-11 w-auto">
  <div class="card p-6 sm:p-8 mt-6">
    <h1 class="font-display text-3xl text-navy">Nova senha</h1>
    <?php if (!$valido): ?>
      <p class="mt-3 text-navy/80">Este link expirou ou já foi usado. Peça um novo.</p>
      <a class="btn mt-5" href="esqueci.php">Pedir novo link</a>
    <?php else: ?>
      <?php if ($erro): ?><p class="mt-4 rounded-xl bg-coral/10 text-coral font-semibold px-4 py-3 text-sm"><?= h($erro) ?></p><?php endif; ?>
      <form method="post" class="mt-5 grid gap-4">
        <input type="hidden" name="csrf" value="<?= h(token_form()) ?>"><input type="hidden" name="t" value="<?= h($t) ?>">
        <label class="grid gap-1 text-sm font-semibold text-navy">Usuário
          <input name="usuario" required minlength="3" autocomplete="username" value="<?= h(ajuste('usuario')) ?>"></label>
        <label class="grid gap-1 text-sm font-semibold text-navy">Nova senha (mínimo 10 caracteres)
          <input name="senha" type="password" required minlength="10" autocomplete="new-password"></label>
        <label class="grid gap-1 text-sm font-semibold text-navy">Repita a nova senha
          <input name="senha2" type="password" required minlength="10" autocomplete="new-password"></label>
        <button class="btn justify-center mt-2">Salvar e entrar</button>
      </form>
    <?php endif; ?>
  </div>
</main>
<?php rodape();
