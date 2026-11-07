# Painel Instituto Formare

Painel com login em https://land.institutoformare.com.br/painel/ (PHP 8 + SQLite, hospedado na Hostinger).

Abas:
- **Landing pages**: contagem própria das landing pages (visitas, seções vistas, tempo, cliques nos botões de compra, origem, aparelho). As páginas enviam os eventos para `coleta.php`.
- **Campanhas**: todas as campanhas das contas de anúncio da Meta (token de usuário do sistema com `ads_read`, cadastrado em Ajustes), com cache de 15 minutos.
- **Vendas**: vendas de todos os produtos da Kiwify, recebidas pelo webhook `kiwify.php` (assinatura HMAC-SHA1 com o token cadastrado em Ajustes). Guarda nome, e-mail e WhatsApp; não guarda CPF nem endereço.
- **Ajustes**: tokens da Kiwify e da Meta, contas de anúncio e troca de senha.

Os dados ficam em `painel-dados/painel.sqlite`, fora da pasta pública (ou em `dados/`, bloqueada pelo `.htaccess`). Nenhum segredo fica no repositório: senha, tokens e banco ficam só no servidor.

Publicação: `POST /api/hosting/v1/accounts/u411572393/websites/land.institutoformare.com.br/git/repositories/deploy` com `directory: painel`.
