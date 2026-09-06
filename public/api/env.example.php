<?php
/**
 * Exemplo de public/api/env.php — o arquivo real NÃO vai para o Git.
 *
 * Ele é gerado automaticamente por scripts/deploy-ftp.sh a partir do .env local.
 * Copie este arquivo para env.php apenas se precisar rodar o PHP à mão.
 *
 * Nenhum destes valores tem padrão embutido no código: constante ausente
 * significa recurso desligado, nunca senha padrão aceita.
 */

// Este arquivo é só referência: acessá-lo direto pelo navegador não devolve nada.
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

// Banco de dados MySQL da hospedagem — usuário DA APLICAÇÃO.
//
// Este usuário só precisa de SELECT/INSERT/UPDATE/DELETE. O schema é criado e
// alterado por `php db/migrate.php`, que roda por SSH com um usuário separado
// (DB_DDL_USER/DB_DDL_PASS) e cujas credenciais NÃO entram neste arquivo: ele
// fica dentro do webroot, e uma configuração errada de servidor que o exponha
// entregaria junto a permissão de DROP TABLE.
define('DB_HOST', 'localhost');
define('DB_NAME', 'exemplo_banco');
define('DB_USER', 'exemplo_usuario');
define('DB_PASS', 'DEFINA_NO_ENV');

// Opcional. Definido, substitui o DSN montado a partir de DB_HOST/DB_NAME —
// para socket Unix, porta fora da 3306 ou outro driver. Deixe fora do arquivo
// para usar o padrão MySQL da hospedagem.
// define('DB_DSN', 'mysql:unix_socket=/tmp/mysql.sock;dbname=exemplo_banco;charset=utf8mb4');

// Login do usuário root criado por api/install.php (padrão: admin)
define('DASHBOARD_ROOT_LOGIN', 'admin');

// Token da instalação única (api/install.php). Vazio = install.php responde 404.
// Gere com: php -r "echo bin2hex(random_bytes(32));"
// Depois de criar o root, apague o valor daqui e do .env.
define('DASHBOARD_INSTALL_TOKEN', '');

// URL pública do relatório Power BI usada para semear o grupo padrão.
// Continua com prefixo NEXT_PUBLIC_ porque o mesmo valor é embutido no bundle
// do site (components/PowerBIViewer.tsx) — não é segredo.
define('NEXT_PUBLIC_POWERBI_URL', '');

// ── Ordem de Serviço: encaminhamento por e-mail e WhatsApp ───────────────────

// Raiz absoluta do site, com ou sem barra final. É o que monta o link com token
// que vai no e-mail e na mensagem de WhatsApp. Sem esta constante o endereço é
// deduzido do cabeçalho Host — que o cliente controla, e que num e-mail enviado
// por nós apontaria o destinatário para o servidor de outra pessoa.
define('SITE_BASE_URL', 'https://www.ecolevaeco.com');

// Remetente do e-mail da OS. Vazio usa o mesmo de public/contact.php.
define('OS_MAIL_FROM', '');

// 'log' registra o destinatário no log e NÃO envia — modo de desenvolvimento e
// da suíte de testes. Vazio (ou ausente) envia de verdade, por mail().
define('MAIL_TRANSPORT', '');

// WhatsApp Cloud API — o "WhatsApp do robô". Ausentes, o botão do robô responde
// 503 e sobra o "Meu WhatsApp", que é só um link wa.me montado no navegador.
define('WHATSAPP_PHONE_ID', '');
define('WHATSAPP_ACCESS_TOKEN', '');

// Template aprovado na Meta. Vazio manda texto livre, que só é entregue dentro
// da janela de 24h depois da última mensagem do cliente.
// Parâmetros do corpo, nesta ordem: {{1}} cliente, {{2}} nº da OS, {{3}} link.
define('WHATSAPP_OS_TEMPLATE', '');
define('WHATSAPP_OS_TEMPLATE_LANG', 'pt_BR');

// 'off' desliga o disparo do robô sem apagar as credenciais acima. Vazio, envia.
define('WHATSAPP_TRANSPORT', '');

// Webhook do WhatsApp (api/webhooks/whatsapp.php). O token é o que a Meta manda
// na verificação da URL; o App Secret assina cada evento e sem ele o webhook
// recusa tudo — corpo não assinado deixaria qualquer um abrindo a janela de 24h.
define('WHATSAPP_WEBHOOK_VERIFY_TOKEN', '');
define('WHATSAPP_APP_SECRET', '');

// Template da fatura: cliente, valor, vencimento e link.
define('WHATSAPP_BILLING_TEMPLATE', '');
define('WHATSAPP_BILLING_TEMPLATE_LANG', 'pt_BR');
