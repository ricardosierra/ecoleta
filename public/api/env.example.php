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
