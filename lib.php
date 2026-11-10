<?php
// Painel Instituto Formare: funções comuns (banco, login, ajustes).
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');
ini_set('display_errors', '0');
// O painel nunca pode ficar guardado no cache do CDN da Hostinger nem do navegador.
if (!headers_sent()) { header('Cache-Control: no-store, no-cache, must-revalidate, private'); header('Pragma: no-cache'); header('CDN-Cache-Control: no-store'); header('X-LiteSpeed-Cache-Control: no-cache'); }

// Código de acesso de uso único (só o hash fica aqui; o código é entregue ao José).
// Serve para o primeiro acesso e para redefinir usuário e senha. Depois de usado, não vale mais;
// para uma nova redefinição, gerar outro código e trocar este hash.
const CODIGO_PRIMEIRO_ACESSO_HASH = '$2y$10$y.r7lPpek3jX6DrSmwT/UOaIz3EwyKIWgUy1xfh2UIxjyyMZc.5Ei';

// Contas de anúncio da Meta consultadas por padrão (podem ser alteradas em Ajustes).
const META_CONTAS_PADRAO = '1163444106038891,2600827373674370';

function pasta_dados(): string
{
    // Preferência: fora da pasta pública (…/domains/institutoformare.com.br/painel-dados).
    $fora = dirname(__DIR__, 3) . '/painel-dados';
    foreach ([$fora, __DIR__ . '/dados'] as $p) {
        if ((is_dir($p) || @mkdir($p, 0700, true)) && is_writable($p)) {
            return $p;
        }
    }
    http_response_code(500);
    exit('Não foi possível criar a pasta de dados do painel.');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . pasta_dados() . '/painel.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000;');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ajustes (chave TEXT PRIMARY KEY, valor TEXT);
        CREATE TABLE IF NOT EXISTS eventos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            criado_em TEXT NOT NULL,
            pagina TEXT NOT NULL,
            tipo TEXT NOT NULL,
            detalhe TEXT,
            segundos INTEGER,
            secao_funda TEXT,
            visitante TEXT,
            sessao TEXT,
            origem TEXT,
            campanha TEXT,
            midia TEXT,
            conteudo TEXT,
            referencia TEXT,
            aparelho TEXT
        );
        CREATE INDEX IF NOT EXISTS ix_eventos_data ON eventos(criado_em);
        CREATE INDEX IF NOT EXISTS ix_eventos_pagina ON eventos(pagina, tipo);
        CREATE TABLE IF NOT EXISTS vendas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pedido TEXT NOT NULL,
            produto_id TEXT NOT NULL DEFAULT '',
            produto TEXT,
            quantidade INTEGER NOT NULL DEFAULT 1,
            valor_centavos INTEGER,
            liquido_centavos INTEGER,
            situacao TEXT,
            pagamento TEXT,
            nome TEXT,
            email TEXT,
            whatsapp TEXT,
            origem TEXT,
            campanha TEXT,
            midia TEXT,
            conteudo TEXT,
            src TEXT,
            sck TEXT,
            criado_em TEXT,
            aprovado_em TEXT,
            atualizado_em TEXT,
            UNIQUE(pedido, produto_id)
        );
        CREATE INDEX IF NOT EXISTS ix_vendas_data ON vendas(criado_em);
        CREATE TABLE IF NOT EXISTS avisos_kiwify (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recebido_em TEXT, pedido TEXT, evento TEXT, aceito INTEGER, motivo TEXT
        );
        CREATE TABLE IF NOT EXISTS cache (chave TEXT PRIMARY KEY, valor TEXT, atualizado_em INTEGER);
        CREATE TABLE IF NOT EXISTS tentativas (ip TEXT, quando INTEGER);
        CREATE TABLE IF NOT EXISTS carrinhos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chave TEXT UNIQUE, criado_em TEXT, produto TEXT, nome TEXT, email TEXT, whatsapp TEXT, link_checkout TEXT, contatado_em TEXT
        );
        CREATE TABLE IF NOT EXISTS links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            criado_em TEXT, pagina TEXT, origem TEXT, campanha TEXT, url TEXT, nota TEXT
        );
    ");
    return $pdo;
}

function ajuste(string $chave, ?string $padrao = null): ?string
{
    $s = db()->prepare('SELECT valor FROM ajustes WHERE chave = ?');
    $s->execute([$chave]);
    $v = $s->fetchColumn();
    return $v === false ? $padrao : $v;
}

function salvar_ajuste(string $chave, ?string $valor): void
{
    if ($valor === null) {
        db()->prepare('DELETE FROM ajustes WHERE chave = ?')->execute([$chave]);
        return;
    }
    db()->prepare('INSERT INTO ajustes (chave, valor) VALUES (?, ?) ON CONFLICT(chave) DO UPDATE SET valor = excluded.valor')
        ->execute([$chave, $valor]);
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function ip_hash(): string
{
    return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (ajuste('sal') ?? ''));
}

function iniciar_sessao(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('painel_formare');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/painel/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function logado(): bool
{
    iniciar_sessao();
    return !empty($_SESSION['ok']) && ($_SESSION['ate'] ?? 0) > time();
}

function exigir_login(): void
{
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    if (ajuste('senha_hash') === null) {
        header('Location: configurar.php');
        exit;
    }
    if (!logado()) {
        header('Location: entrar.php');
        exit;
    }
    $_SESSION['ate'] = time() + 8 * 3600;
}

function token_form(): string
{
    iniciar_sessao();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function conferir_form(): void
{
    iniciar_sessao();
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Formulário expirado. Volte e tente de novo.');
    }
}

function registrar_tentativa(): void
{
    // Sem bloqueio por tempo: cada tentativa errada só demora 2 segundos, o que impede testar senhas em massa.
    sleep(2);
}

// Envia e-mail pelo servidor da Hostinger (remetente do próprio domínio).
function enviar_email(string $para, string $assunto, string $texto): bool
{
    $de = 'contato@institutoformare.com.br';
    $cab = "From: Painel Instituto Formare <$de>\r\nReply-To: $de\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    return @mail($para, '=?UTF-8?B?' . base64_encode($assunto) . '?=', $texto, $cab, '-f' . $de);
}

function email_valido(string $e): bool
{
    return (bool)filter_var($e, FILTER_VALIDATE_EMAIL);
}

function reais(?int $centavos): string
{
    return 'R$ ' . number_format(($centavos ?? 0) / 100, 2, ',', '.');
}

function numero($n, int $casas = 0): string
{
    return number_format((float)$n, $casas, ',', '.');
}

function pct($parte, $todo): string
{
    return $todo > 0 ? numero(100 * $parte / $todo, 1) . '%' : '—';
}

function cabecalho(string $titulo): void
{
    ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($titulo) ?> | Painel Instituto Formare</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com/3.4.16"></script>
<script>
  if (window.tailwind) tailwind.config = { theme: { extend: {
    colors: { navy: { DEFAULT: '#13285A', 700: '#0E1F47', 900: '#0A1633' }, leaf: { DEFAULT: '#1B8A3A', 50: '#E9F7EE' }, sky: { DEFAULT: '#1F6FE0' }, cream: '#FBF6EC', mint: { DEFAULT: '#D3F1E3', 300: '#A9E4CA' }, coral: '#E9573A', sun: '#F7B928' },
    fontFamily: { display: ['Anton', 'Impact', 'sans-serif'], sans: ['Inter', 'system-ui', 'sans-serif'] }
  } } };
</script>
<style>
  body { background: #FBF6EC; color: #1d2b4a; }
  .font-display { text-transform: uppercase; letter-spacing: .01em; line-height: 1.05; }
  .card { background: #fff; border-radius: 1.25rem; box-shadow: 0 10px 30px -14px rgba(19,40,90,.25); }
  .tab { padding: .55rem 1rem; border-radius: 9999px; font-weight: 700; font-size: .9rem; white-space: nowrap; }
  .tab-on { background: #13285A; color: #fff; }
  .tab-off { background: #fff; color: #13285A; }
  table.t { width: 100%; font-size: .875rem; border-collapse: collapse; }
  table.t th { text-align: left; font-weight: 700; color: #13285A; background: #F1F5F3; padding: .55rem .7rem; white-space: nowrap; }
  table.t td { padding: .55rem .7rem; border-top: 1px solid #EEF1F0; vertical-align: top; }
  table.t td.n, table.t th.n { text-align: right; white-space: nowrap; }
  .barra { height: .55rem; border-radius: 9999px; background: #1B8A3A; }
  .btn { display: inline-flex; align-items: center; gap: .4rem; background: #1B8A3A; color: #fff; font-weight: 700; border-radius: 9999px; padding: .6rem 1.1rem; }
  .btn2 { display: inline-flex; align-items: center; gap: .4rem; background: #fff; color: #13285A; font-weight: 700; border-radius: 9999px; padding: .55rem 1rem; border: 1px solid #D5DCE6; }
  html, body { overflow-x: hidden; }
  code { word-break: break-all; white-space: normal; }
  input, select { max-width: 100%; }
  label { min-width: 0; max-width: 100%; }
  input, select { border: 1px solid #D5DCE6; border-radius: .7rem; padding: .55rem .75rem; background: #fff; }
  @media print {
    @page { size: A4 landscape; margin: 10mm; }
    body { background: #fff; }
    header nav, form, .no-print, header a { display: none !important; }
    .card { box-shadow: none; border: 1px solid #e3e7ea; break-inside: avoid; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
  a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible { outline: 3px solid #F7B928; outline-offset: 2px; }
</style>
</head>
<body class="font-sans min-h-screen">
<?php
}

function rodape(): void
{
    echo "</body>\n</html>\n";
}
