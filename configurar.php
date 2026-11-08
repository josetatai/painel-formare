<?php
// Primeiro acesso ou redefinição: cria (ou troca) o usuário e a senha do painel com o código de uso único.
require __DIR__ . '/lib.php';
header('X-Robots-Tag: noindex, nofollow');

$redefinir = ajuste('senha_hash') !== null;
$codigoUsado = hash_equals((string)ajuste('codigo_usado'), CODIGO_PRIMEIRO_ACESSO_HASH);
if ($redefinir && $codigoUsado) {
    cabecalho('Redefinir acesso');
    echo '<main class="max-w-md mx-auto px-4 py-12"><div class="card p-6 sm:p-8"><h1 class="font-display text-3xl text-navy">Redefinir acesso</h1>'
       . '<p class="mt-3 text-navy/80">Para redefinir o usuário e a senha é preciso um código novo. Peça um novo código de acesso.</p>'
       . '<a class="btn mt-5" href="entrar.php">Voltar</a></div></main>';
    rodape();
    exit;
}

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    conferir_form();
    $codigo = strtoupper(trim((string)($_POST['codigo'] ?? '')));
    $usuario = trim((string)($_POST['usuario'] ?? ''));
    $senha = (string)($_POST['senha'] ?? '');
    $senha2 = (string)($_POST['senha2'] ?? '');
    if (bloqueado()) {
        $erro = 'Muitas tentativas. Espere 15 minutos.';
    } elseif ($codigoUsado || !password_verify($codigo, CODIGO_PRIMEIRO_ACESSO_HASH)) {
        registrar_tentativa();
        $erro = 'Código de acesso incorreto.';
    } elseif (mb_strlen($usuario) < 3) {
        $erro = 'O usuário precisa ter pelo menos 3 letras.';
    } elseif (mb_strlen($senha) < 10) {
        $erro = 'A senha precisa ter pelo menos 10 caracteres.';
    } elseif ($senha !== $senha2) {
        $erro = 'As duas senhas não são iguais.';
    } else {
        if (!$redefinir) salvar_ajuste('sal', bin2hex(random_bytes(16)));
        salvar_ajuste('usuario', $usuario);
        salvar_ajuste('senha_hash', password_hash($senha, PASSWORD_DEFAULT));
        salvar_ajuste('codigo_usado', CODIGO_PRIMEIRO_ACESSO_HASH);
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        $_SESSION['ate'] = time() + 8 * 3600;
        header('Location: ./?aba=ajustes' . ($redefinir ? '' : '&novo=1'));
        exit;
    }
}

cabecalho($redefinir ? 'Redefinir acesso' : 'Primeiro acesso');
?>
<main class="max-w-md mx-auto px-4 py-12">
  <img src="../livro-licitacoes/img/logo-formare.webp" alt="Instituto Formare" class="h-11 w-auto">
  <div class="card p-6 sm:p-8 mt-6">
    <h1 class="font-display text-3xl text-navy"><?= $redefinir ? 'Redefinir acesso' : 'Primeiro acesso' ?></h1>
    <p class="mt-2 text-sm text-navy/70"><?= $redefinir ? 'Use o código de acesso para criar um novo usuário e uma nova senha. Os dados do painel continuam iguais.' : 'Crie o seu usuário e a sua senha. Isso só é feito uma vez.' ?></p>
    <?php if ($erro): ?><p class="mt-4 rounded-xl bg-coral/10 text-coral font-semibold px-4 py-3 text-sm"><?= h($erro) ?></p><?php endif; ?>
    <form method="post" class="mt-5 grid gap-4" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(token_form()) ?>">
      <label class="grid gap-1 text-sm font-semibold text-navy">Código de acesso
        <input name="codigo" required placeholder="XXXX-XXXX-XXXX" autocomplete="one-time-code"></label>
      <label class="grid gap-1 text-sm font-semibold text-navy">Usuário
        <input name="usuario" required minlength="3" autocomplete="username"></label>
      <label class="grid gap-1 text-sm font-semibold text-navy">Senha (mínimo 10 caracteres)
        <input name="senha" type="password" required minlength="10" autocomplete="new-password"></label>
      <label class="grid gap-1 text-sm font-semibold text-navy">Repita a senha
        <input name="senha2" type="password" required minlength="10" autocomplete="new-password"></label>
      <button class="btn justify-center mt-2"><?= $redefinir ? 'Salvar novo acesso' : 'Criar acesso' ?></button>
    </form>
  </div>
</main>
<?php rodape();
