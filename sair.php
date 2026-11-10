<?php
// Sai do painel: apaga a sessão e o cookie e volta para o login.
require __DIR__ . '/lib.php';
iniciar_sessao();
$_SESSION = [];
$p = session_get_cookie_params();
setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
session_destroy();
header('Clear-Site-Data: "cookies"');
header('Location: entrar.php?saiu=' . time());
