<?php
// Esqueci a senha: envia para o e-mail cadastrado um link de uso único, válido por 1 hora.
require __DIR__ . '/lib.php';
header('X-Robots-Tag: noindex, nofollow');

$enviado = false;
$erro = '';
$temEmail = email_valido((string)ajuste('email', ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $temEmail) {
    conferir_form();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (hash_equals(strtolower((string)ajuste('email')), $email)) {
        $t = bin2hex(random_bytes(24));
        salvar_ajuste('reset_hash', hash('sha256', $t));
        salvar_ajuste('reset_ate', (string)(time() + 3600));
        $link = 'https://land.institutoformare.com.br/painel/redefinir.php?t=' . $t;
        enviar_email($email, 'Redefinir a senha do Painel Instituto Formare',
            "Olá!\n\nVocê pediu para redefinir o acesso ao Painel Instituto Formare.\n\nSeu usuário atual: " . ajuste('usuario') .
            "\n\nClique no link abaixo para criar uma nova senha (vale por 1 hora e só pode ser usado uma vez):\n\n$link\n\n" .
            "Se não foi você, ignore este e-mail. Sua senha atual continua valendo.\n\nPainel Instituto Formare");
    } else {
        registrar_tentativa();
    }
    // A mensagem é sempre a mesma, para não revelar qual e-mail está cadastrado.
    $enviado = true;
}

cabecalho('Esqueci a senha');
?>
<main class="max-w-md mx-auto px-4 py-12">
  <img src="../livro-licitacoes/img/logo-formare.webp" alt="Instituto Formare" class="h-11 w-auto">
  <div class="card p-6 sm:p-8 mt-6">
    <h1 class="font-display text-3xl text-navy">Esqueci a senha</h1>
    <?php if (!$temEmail): ?>
      <p class="mt-3 text-navy/80">Ainda não há e-mail de recuperação cadastrado. Use o código de acesso para criar um novo acesso e cadastrar o seu e-mail.</p>
      <a class="btn mt-5" href="configurar.php">Usar código de acesso</a>
    <?php elseif ($enviado): ?>
      <p class="mt-3 text-navy/80">Se o e-mail estiver certo, você vai receber em instantes um link para criar uma nova senha. O link vale por <strong>1 hora</strong>.</p>
      <p class="mt-3 text-sm text-navy/60">Não chegou? Confira a caixa de <strong>spam</strong> e a aba <strong>Promoções</strong>.</p>
      <a class="btn2 mt-5" href="entrar.php">Voltar para o login</a>
    <?php else: ?>
      <p class="mt-2 text-sm text-navy/70">Digite o e-mail cadastrado no painel. Vamos mandar um link para você criar uma nova senha. O e-mail também lembra o seu usuário.</p>
      <form method="post" class="mt-5 grid gap-4">
        <input type="hidden" name="csrf" value="<?= h(token_form()) ?>">
        <label class="grid gap-1 text-sm font-semibold text-navy">E-mail
          <input name="email" type="email" required autocomplete="email"></label>
        <button class="btn justify-center mt-2">Enviar link</button>
      </form>
      <p class="mt-4 text-sm text-center"><a class="text-navy/70 underline" href="entrar.php">Voltar para o login</a></p>
    <?php endif; ?>
  </div>
</main>
<?php rodape();
