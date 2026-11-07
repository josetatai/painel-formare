<?php
// Baixa as vendas do período em planilha (CSV que abre no Excel).
require __DIR__ . '/lib.php';
exigir_login();

$de = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['de'] ?? '') ? $_GET['de'] : date('Y-m-d', strtotime('-29 days'));
$ate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['ate'] ?? '') ? $_GET['ate'] : date('Y-m-d');
$sql = 'SELECT * FROM vendas WHERE criado_em BETWEEN ? AND ?';
$par = [$de . ' 00:00:00', $ate . ' 23:59:59'];
if (($_GET['produto'] ?? '') !== '') { $sql .= ' AND produto = ?'; $par[] = $_GET['produto']; }
if (($_GET['situacao'] ?? '') !== '') { $sql .= ' AND situacao = ?'; $par[] = $_GET['situacao']; }
$s = db()->prepare($sql . ' ORDER BY criado_em');
$s->execute($par);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="vendas-' . $de . '-a-' . $ate . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Data', 'Hora', 'Produto', 'Quantidade', 'Valor (R$)', 'Líquido (R$)', 'Nome', 'WhatsApp', 'E-mail', 'Pagamento', 'Situação', 'Origem', 'Campanha', 'Pedido'], ';');
while ($v = $s->fetch()) {
    $t = strtotime($v['criado_em']);
    fputcsv($out, [date('d/m/Y', $t), date('H:i', $t), $v['produto'], $v['quantidade'],
        $v['valor_centavos'] !== null ? number_format($v['valor_centavos'] / 100, 2, ',', '') : '',
        $v['liquido_centavos'] !== null ? number_format($v['liquido_centavos'] / 100, 2, ',', '') : '',
        $v['nome'], $v['whatsapp'], $v['email'], $v['pagamento'], $v['situacao'], $v['origem'], $v['campanha'], $v['pedido']], ';');
}
