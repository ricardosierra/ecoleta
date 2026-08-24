# Release Notes

---

## [Futuro]

## [Unreleased](https://github.com/ricardosierra/ecoleta/compare/v1.2.0...master)

### ✨ Novidades

- [x] **Primeira suíte de testes** — 224 casos, nenhum antes. No front, Vitest e Testing Library sobre `lib/contact-schema.ts`, `lib/rate-limit.ts`, `lib/dashboard-api.ts`, o módulo de papéis e as telas de gestão. No backend, PHPUnit sobre as regras de papel, a trilha de auditoria e os endpoints rodando de verdade.
- [x] **Integração contínua** — `.github/workflows/ci.yml` roda em todo push e pull request: typecheck, lint, testes de front, `php -l`, PHPUnit, a suíte do runner de migrations e o build de export estático. Dois jobs em paralelo, sem serviço de banco no runner.
- [x] **Migrations versionadas** — o schema saiu do caminho da requisição. `db/migrations/*.sql` guarda o DDL e o seed numerados e idempotentes, `db/migrate.php` aplica os pendentes por SSH/CLI e `schema_migrations` registra versão, arquivo, checksum e duração de cada um.
- [x] **Instalação única do root** — `api/install.php` cria o usuário `root` uma só vez, exige `DASHBOARD_INSTALL_TOKEN` pelo cabeçalho `X-Install-Token`, devolve a senha sorteada uma única vez e se autodesativa (`.install-lock`), respondendo 404 daí em diante.
- [x] **Rate limit de login no servidor** — contadores em MySQL por `(login, IP)` e por IP, com janela de 15 minutos, bloqueio progressivo até o teto de 15 minutos e resposta `429` com `Retry-After`. O contador por IP pega o password spraying, que troca de login a cada tentativa e nunca chegaria ao limite do primeiro.
- [x] **Token CSRF por sessão** — emitido por `api/auth/me.php`, inclusive antes do login, e exigido no cabeçalho `X-CSRF-Token` em todo endpoint que não seja GET.

### 🎨 Melhorias

- [x] **Requisição sem DDL** — `getDbConnection()` só conecta. Antes, toda chamada autenticada rodava a criação das cinco tabelas, a inspeção das colunas de `users`, uma alteração condicional dessa tabela e duas consultas de seed: o guard `static` morria junto com o processo PHP, então o custo se repetia sempre. Sobrou uma leitura em `schema_migrations` por requisição.
- [x] **503 em vez de auto-conserto** — banco atrás do código devolve `503 schema_out_of_date` com o motivo apenas no log do servidor, em vez de tentar se consertar com DDL sob concorrência. Banco à frente do código continua servindo: é o estado normal entre aplicar as migrations e subir os arquivos.
- [x] **Cookie de sessão endurecido** — `HttpOnly`, `SameSite=Lax`, `Secure` sob HTTPS e escopo restrito a `/api/`, com `session.use_strict_mode` ligado e expiração por inatividade de 8 horas.
- [x] **Respostas de login indistinguíveis** — usuário inexistente e senha errada devolvem a mesma mensagem e gastam o mesmo tempo de CPU, fechando a enumeração de logins tanto pelo conteúdo quanto pelo tempo de resposta.
- [x] **Logout passou a ser POST com token** — antes, um `<img src="…/logout.php">` em qualquer página derrubava a sessão de quem a abrisse.
- [x] Respostas JSON com `Cache-Control: no-store`, `X-Content-Type-Options: nosniff` e `Referrer-Policy: same-origin`.

### 🐛 Correções

- [x] **Conta sem volta ao mudar o próprio papel** — `users/delete.php` recusava apagar a própria conta, mas `users/edit.php` aceitava rebaixá-la: o único `root` que abrisse a própria edição e escolhesse "Usuário Padrão" gravava `role = 'user'` e deixava a instalação com zero administradores. `install.php` se autodesativa com `.install-lock` assim que cria a conta e responde 404 desde então, então a volta só existiria por acesso direto ao banco. Não era escalada — ninguém ganhava privilégio — era perda irreversível dele, pela porta do lado da trava que já existia para a exclusão. Agora o papel da própria conta é sempre mantido, dos dois lados; o resto dos dados continua editável.
- [x] **Sessão de papel desconhecido em `auth/change_password.php`** — era o último endpoint lendo `$_SESSION['user_id']` na mão, enquanto todo o resto da API trata papel desconhecido como sessão não autenticada. Não dava para alcançar outra conta (o alvo é sempre o id da sessão, nunca o do corpo), mas era uma porta com regra própria. Passou a usar `apiRequireAuthenticated()`.
- [x] **Gestão de usuários aberta a qualquer sessão** — `/dashboard/usuarios` e `/dashboard/usuarios/ver` montavam a tela inteira de administração de contas para qualquer sessão autenticada, e só dependiam do 403 da API para não exibir dados. Na listagem isso parava no erro de carregamento; em `/ver` não parava, porque `users/logs.php` libera o próprio histórico para qualquer papel — um `user` abrindo `?id=<o próprio id>` recebia 200 e via a tela de administração montada sobre os próprios dados. Os botões já ficavam escondidos e o backend recusaria cada chamada: o que vazava era a superfície, não o poder. As duas telas passaram a usar o mesmo bloqueio de `/dashboard/grupos` e nem chegam a pedir os dados.
- [x] **Fixação de sessão** — `session_regenerate_id(true)` passou a rodar logo após o login e após a troca de senha; antes, o ID emitido antes da autenticação continuava válido depois dela.
- [x] **Bootstrap implícito de root** — `auth/login.php` criava sozinho um usuário `root` quando o login `admin` não existia, bastando acertar a senha padrão. Removido em favor da instalação explícita.
- [x] **Senha padrão embutida** — o fallback `'ecoleta2026'` saiu do `db.php`. Sem a variável definida o recurso falha fechado e registra o motivo no log, em vez de aceitar uma senha conhecida.

### 🔧 Técnico

**Regra de papel em dois módulos, um por lado:** `lib/authz.ts` e `public/api/authz.php` guardam a mesma tabela de decisões — quem é administrador, sobre quem cada papel age, qual papel uma edição realmente grava, quem enxerga o histórico de quem. Antes ela estava copiada dentro das telas de 767, 689 e 490 linhas e repetida em sete endpoints, cada um decidindo por conta própria o que `master` pode fazer sobre `root`; `groups/index.php` já respondia com uma mensagem de recusa diferente das outras seis. O servidor é o lado que decide: o cliente só escolhe o que desenhar, e o ator sai sempre da sessão, nunca do corpo da requisição.

- [x] `tests/php/Support/` — Endpoint::call() roda cada script de `public/api/` em um processo PHP novo, com $_SERVER, sessão e `php://input` montados (o SAPI de linha de comando não preenche o corpo sozinho). Devolve status, corpo, `error_log` e a sessão como o endpoint a deixou.
- [x] Banco de teste em SQLite descartável, um por caso, apontado por `DB_DSN`. `apiDatabaseDsn()` é a única mudança em código de produção: aceita um DSN explícito e cai no MySQL montado de `DB_HOST`/`DB_NAME` quando não há — o escape que faltava para socket Unix ou porta fora da 3306.
- [x] O rate limit não é portável para SQLite de propósito (`NOW()`, `TIMESTAMPDIFF`, `ON DUPLICATE KEY`), e é isso que prova a falha aberta: com o throttle fora do ar o login continua atendendo e registra o motivo, como faria em produção.
- [x] `SchemaMirrorTest` lê `db/migrations/*.sql` e compara com o espelho SQLite; `testEnumeraTodosOsEndpointsDeAdministracao()` compara a lista coberta com o que existe em `users/` e `groups/`. Endpoint novo ou coluna nova falham a suíte até serem considerados.
- [x] PHPUnit entra como `.phar` baixado por `scripts/phpunit.sh`, com SHA-256 conferido a cada execução. Continua valendo o "sem Composer": um `composer.json` na raiz sugere um autoloader que este backend não tem, e a hospedagem compartilhada não roda `composer install`.
- [x] `npm run typecheck`, `npm test`, `npm run test:php` — os dois primeiros não existiam; o `package.json` tinha só `lint`.

**Segredos:** `NEXT_PUBLIC_DASHBOARD_USER` virou `DASHBOARD_ROOT_LOGIN` e `NEXT_PUBLIC_DASHBOARD_PASSWORD` deu lugar a `DASHBOARD_INSTALL_TOKEN`. O prefixo `NEXT_PUBLIC_` faz o Next.js embutir o valor no bundle servido ao navegador — nome errado para segredo de servidor. `NEXT_PUBLIC_POWERBI_URL` mantém o prefixo de propósito: é lida pelo `PowerBIViewer` e não é segredo.

- [x] `public/api/security.php` — sessão, CSRF, resposta JSON e leitura de segredos sem valor padrão, compartilhados por todos os endpoints.
- [x] `public/api/rate_limit.php` — contadores de login persistidos em MySQL, com todo o cálculo de tempo feito pelo banco (`NOW()`, `TIMESTAMPDIFF`) para não depender de PHP e MySQL estarem no mesmo fuso.
- [x] `lib/dashboard-api.ts` — cliente que guarda o token CSRF em memória, busca sozinho quando falta e refaz a requisição uma vez quando o servidor avisa que o token venceu. Todos os POST do dashboard passam por ele.
- [x] Tabela `login_throttle` (migration `005`), com limpeza probabilística para não crescer sem fim; `.install-lock` adicionado ao `.gitignore`.
- [x] `scripts/deploy-ftp.sh` gera o `env.php` com os novos nomes e avisa quando o token de instalação ainda está ativo no deploy.
- [x] Nenhuma dependência nova e nenhum Composer: PHP 8 puro, como a hospedagem compartilhada exige.

**Privilégio de banco:** o usuário MySQL da aplicação passa a precisar apenas de `SELECT/INSERT/UPDATE/DELETE`. O DDL é de um usuário separado (`DB_DDL_USER`), usado só pelo runner via SSH e nunca gravado em `public/api/env.php` — arquivo que fica dentro do webroot e que, exposto por engano, entregava junto a permissão de `DROP TABLE`.

- [x] `public/api/schema.php` — `ECOLETA_SCHEMA_VERSION` declara a versão que o código exige; `db/migrate.php` compara com a última migration em disco e recusa terminar se divergirem, para a constante não ficar para trás em silêncio.
- [x] Migration já aplicada é imutável: o checksum SHA-256 fica no registro e o runner para quando o arquivo muda depois de aplicado.
- [x] `db/migrate.php` recusa rodar fora do CLI e `db/` não é publicado pelo deploy (só `out/` sobe por FTP).
- [x] `scripts/deploy-ftp.sh` exige a confirmação de que as migrations já rodaram antes de compilar e publicar — interativamente ou com `MIGRATIONS_APPLIED=1` em CI.
- [x] `docs/deploy.md` — ordem do deploy (migrations → arquivos), os dois `GRANT`, o procedimento de nova migration e o diagnóstico do 503.

## [v1.2.0 (2026-08-21)](https://github.com/ricardosierra/ecoleta/compare/v1.0.4...v1.2.0)

### ✨ Novidades

- [x] **Gestão de Grupos (`/dashboard/grupos/`)**: Tela exclusiva para `root` e `master` com cadastro, edição (nome e código/link de Power BI embed) e exclusão segura com validação de usuários vinculados.
- [x] **Grupos Pré-criados**: Grupos `Coleta` (com Power BI padrão) e `Infectantes` (pronto para cadastro de código/URL de incorporação) criados automaticamente via banco de dados.
- [x] **Edição e Exclusão de Usuários (`/dashboard/usuarios/`)**: Botões e modais de edição de dados (login, e-mail, perfil, grupo) e exclusão com auditoria.
- [x] **Associação Obrigatória a Grupos**: Usuários comuns (`user`) são obrigatoriamente vinculados a um grupo.
- [x] **Dashboard Dinâmico por Grupo (`/dashboard/`)**: Exibição dinâmica do Power BI correspondente ao grupo do usuário logado; administradores (`root` e `master`) contam com seletor rápido para alternar entre os grupos.
- [x] **Controle de Acesso**: Acesso a visualização e gestão de grupos restrito exclusivamente a `root` e `master`.
- [x] Geração e redefinição de senhas temporárias com controle de permissões por perfil (`root` para todos, `master` apenas para usuários padrão)
- [x] Exclusão segura de usuários com confirmação em modal e regras de autorização (`root` para todos exceto a si próprio, `master` para usuários comuns)
- [x] Sistema completo de auditoria e histórico de logs de atividades (`activity_logs`) registrando logins, logouts, cadastros, alterações e exclusões
- [x] Nova visualização detalhada de histórico do usuário com badges visuais temáticos, executor, IP e identificação do dispositivo
- [x] Cópia em um clique da senha gerada diretamente na interface com feedback visual

### 🔧 Técnico

- [x] Tabela `groups` criada e migração automática de `users.group_id`.
- [x] Sanitização e extração automática de URL a partir de tags `<iframe>` do Power BI coladas por administradores.
- [x] Registro de auditoria em `activity_logs` para operações de grupos (`create_group`, `edit_group`, `delete_group`) e edição de usuários (`edit_user`).
- [x] Endpoints criados: `/api/groups/index.php`, `/api/groups/edit.php`, `/api/groups/delete.php` e `/api/users/edit.php`.
- [x] Componente `PowerBIViewer` parametrizado dinamicamente para aceitar URL de grupo com estado vazio amigável.
- [x] Build estático validado com sucesso com `npm run build` e `npm run lint`.
- [x] Criação e validação automática das tabelas `users`, `access_logs` e `activity_logs` via `db.php`
- [x] Exigência mandatória de troca de senha no primeiro login (`force_password_change`)
- [x] Validação dupla de perfis e permissões no backend (PHP) e no frontend (React)
- [x] Endpoints criados: `/api/users/generate_password.php` e `/api/users/delete.php`
- [x] Endpoints atualizados para auditoria: `login.php`, `logout.php`, `change_password.php`, `users/index.php` e `users/logs.php`
- [x] Build estático regenerado e empacotado em `ecoleta-out.zip`
- [x] Validação executada com `npm run build` e `npm run lint`

## [v1.0.4 (2026-08-10)](https://github.com/ricardosierra/ecoleta/compare/v1.0.3...v1.0.4)

### 🎨 Melhorias

- [x] Painel BI público adicionado em `/dashboard` com embed do Power BI, recarregamento e modo tela cheia
- [x] Navegação e rodapé atualizados com acesso ao Painel BI e destaque correto do item Início
- [x] Indicadores ESG, logos de clientes e imagem da página Sobre atualizados para a identidade Ecoleva
- [x] Grade de clientes simplificada para melhorar leitura, responsividade e acessibilidade

### 🐛 Correções

- [x] Autenticação simulada do dashboard removida: senhas e variáveis `NEXT_PUBLIC_*` não são usadas no client

### 🔧 Técnico

- [x] Configurações de banco e FTP mantidas como placeholders em `.env.example`, sem identificadores ou credenciais reais
- [x] Script `npm run deploy:ftp` adicionado para publicação com credenciais lidas exclusivamente do `.env` local
- [x] `robots.txt` configurado para não indexar `/dashboard/`
- [x] Build estático regenerado e empacotado em `ecoleta-out.zip`
- [x] Validação executada com `npm run build` e `npm run lint`

## [v1.0.3 (2026-05-23)](https://github.com/ricardosierra/ecoleta/compare/v1.0.2...v1.0.3)

### 🎨 Melhorias

- [x] Rebranding textual e visual de Ecoleta para Ecoleva em páginas, metadados, feed, manifesto, formulário e e-mails de contato
- [x] Novas marcas Ecoleva aplicadas nas variações clara e escura, com ajustes em favicon, ícones PWA e imagem OG
- [x] Carrossel de clientes revisado com logos corrigidos e inclusão de GH Music e Subsea 7
- [x] Cards de impacto ESG redesenhados com fundo escuro, ícones próprios e maior hierarquia visual
- [x] Página Sobre atualizada com novas imagens de equipe, triagem e pontos de coleta sustentável
- [x] Página Soluções recebeu ícones próprios para as frações de resíduos atendidas
- [x] Rodapé atualizado com assinatura de desenvolvimento RicaSolucoes e SierraTecnologia

### 🐛 Correções

- [x] Logo branca corrigida para usar asset branco real em fundos escuros
- [x] Menu mobile fecha ao navegar, fecha com Escape e bloqueia interação quando está oculto
- [x] Animação `LetterReveal` passou a revelar o texto completo para evitar palavras parciais em títulos
- [x] Ajustes de tamanho, espaçamento e alinhamento em cards, botões e listas para melhorar leitura responsiva

### 🔧 Técnico

- [x] Novos ícones SVG internos adicionados sem dependência de UI externa
- [x] Configurações centrais atualizadas em `siteConfig`, manifesto, feed XML e `contact.php`
- [x] Build estático regenerado e empacotado em `ecoleta-out.zip`
- [x] Validação executada com `npm run build` e `npm run lint`

## [v1.0.1 (2026-05-13)](https://github.com/ricardosierra/ecoleta/compare/v1.0.0...v1.0.1)

### 🎨 Melhorias

- [x] Nova identidade visual aplicada a logo, favicon, ícones PWA e imagem OG a partir da arte atualizada da cliente
- [x] Escala visual geral reduzida em títulos, cards, botões e espaçamentos para melhorar leitura em desktop e mobile
- [x] Vídeo inicial mais visível e com reprodução desacelerada no hero da Home
- [x] Home atualizada com novos textos de diferenciais, CTA final e indicadores de desvio de aterro em 92%
- [x] Página Soluções atualizada com pilares de gestão operacional, ESG aplicado, conformidade e frações de resíduos revisadas
- [x] Página ESG reorganizada com cards escuros, textos novos de Ambiental/Social/Governança e comprovação ambiental completa
- [x] Página Sobre recebeu o case modelo, galeria sem legendas, foto da equipe, dashboard e imagens de treinamento
- [x] Rodapé atualizado com texto final solicitado e sem telefone comercial duplicado

### 🐛 Correções

- [x] Animações de palavras e letras ficam estáticas no mobile para evitar travamentos
- [x] Percentuais antigos de 68%, 93%, 94% e 96% foram padronizados conforme solicitação da cliente
- [x] Galeria não exibe mais legenda sobre as fotos e remove foto repetida

### 🔧 Técnico

- [x] Página `Cases` removida da navegação, sitemap e build estático
- [x] Conteúdo remanescente de `Cases` redistribuído entre `Sobre` e `ESG`
- [x] Componente `Gallery` simplificado para aceitar apenas `src` e `alt`
- [x] Componente `Logo` passa a usar assets processados por variante visual
- [x] Componente `HeroVideo` centraliza controle de velocidade do vídeo inicial
