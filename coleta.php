<?php
// Recebe os eventos das landing pages (visita, seção, tempo, clique). Sem dados pessoais.
require __DIR__ . '/lib.php';
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
$origemReq = parse_url((string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST);
if ($origemReq !== null && $origemReq !== '' && $origemReq !== 'land.institutoformare.com.br') {
    http_response_code(403);
    exit;
}

$bruto = file_get_contents('php://input', false, null, 0, 4096);
$e = json_decode((string)$bruto, true);
if (!is_array($e)) {
    http_response_code(400);
    exit;
}

function campo($v, int $max = 80): ?string
{
    if ($v === null || $v === '') return null;
    $v = preg_replace('/[^\p{L}\p{N} ._:\/\-]/u', '', (string)$v);
    return mb_substr($v, 0, $max);
}

$tipos = ['visita', 'secao', 'tempo', 'clique'];
$tipo = (string)($e['t'] ?? '');
$pagina = campo($e['p'] ?? '', 60);
if (!in_array($tipo, $tipos, true) || !$pagina) {
    http_response_code(400);
    exit;
}

// Limite simples contra abuso: até 120 eventos por minuto por visitante.
$visitante = campo($e['v'] ?? '', 40);
$s = db()->prepare("SELECT COUNT(*) FROM eventos WHERE visitante = ? AND criado_em >= ?");
$s->execute([$visitante, date('Y-m-d H:i:s', time() - 60)]);
if ((int)$s->fetchColumn() > 120) {
    http_response_code(429);
    exit;
}

$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
if (preg_match('/bot|crawl|spider|facebookexternalhit|preview|headless/i', $ua)) {
    http_response_code(204);
    exit;
}
$aparelho = preg_match('/iPad|Tablet/i', $ua) ? 'tablet' : (preg_match('/Mobi|Android|iPhone/i', $ua) ? 'celular' : 'computador');

$ref = parse_url((string)($e['r'] ?? ''), PHP_URL_HOST) ?: null;

db()->prepare('INSERT INTO eventos (criado_em, pagina, tipo, detalhe, segundos, secao_funda, visitante, sessao, origem, campanha, midia, conteudo, referencia, aparelho)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute([
        date('Y-m-d H:i:s'),
        $pagina,
        $tipo,
        campo($e['d'] ?? null, 40),
        isset($e['s']) ? max(0, min(86400, (int)$e['s'])) : null,
        campo($e['f'] ?? null, 40),
        $visitante,
        campo($e['ss'] ?? '', 40),
        campo($e['us'] ?? null) ?: 'direto',
        campo($e['uc'] ?? null),
        campo($e['um'] ?? null),
        campo($e['ut'] ?? null),
        $ref ? mb_substr($ref, 0, 80) : null,
        $aparelho,
    ]);

http_response_code(204);
