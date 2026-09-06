# Release Notes

---

## [Futuro]

## [Unreleased]

### ✨ Novidades

- [x] **Histórico de WhatsApp no banco** — toda mensagem recebida e enviada passa a ficar guardada (migration 015: `whatsapp_conversations` e `whatsapp_messages`). Estrutura adaptada do `banlek-whatsapp-service`, sem a parte que este projeto não tem: mídia em S3, transcrição de áudio e sugestões de IA.
- [x] **Webhook do WhatsApp** — `api/webhooks/whatsapp.php` responde à verificação da URL da Meta, grava as mensagens que chegam e aplica as confirmações de entrega (`sent` → `delivered` → `read` → `failed`, sem retroceder). Cada evento é autenticado pela assinatura `X-Hub-Signature-256`, HMAC-SHA256 do corpo cru com o App Secret: sem `WHATSAPP_APP_SECRET` configurado o endpoint recusa tudo, porque aceitar corpo não assinado deixaria qualquer um inventando mensagem de cliente e abrindo a janela de 24h por conta própria. Reentrega da Meta não duplica nada — o índice único em `wa_message_id` é a idempotência.
- [x] **Janela de 24 horas visível na OS** — o botão do robô fica **verde** quando o cliente escreveu para o nosso número nas últimas 24 horas, com tooltip dizendo que o envio é gratuito. Fora da janela o botão fica neutro e o tooltip avisa que exige template aprovado e é cobrado pela Meta.
- [x] **Envio escolhe o formato pela janela** — dentro dela o robô manda texto livre (não cobrado); fora, template aprovado. Sem template configurado, nem tenta: a Meta recusaria de qualquer jeito, e a tela oferece o WhatsApp pessoal como saída.
- [x] **Tela de conversas** (`/dashboard/whatsapp`) — lista à esquerda com prévia, carimbo, não lidas e o estado da janela por cor; conversa à direita com bolhas nas cores do WhatsApp, separadores de dia, tiques de entrega e a faixa que diz se dá para responder agora. Layout baseado no painel do `banlek-whatsapp-service`. É tela de leitura: responder por ela não faz parte desta entrega.
- [x] **Painel restrito a uma conta** — `/dashboard/whatsapp` e os dois endpoints exigem `root` **e** e-mail na lista de `apiRoleCanViewWhatsAppPanel()` (hoje só `sierra.csi@gmail.com`). As duas condições juntas: só o papel deixaria qualquer root futuro lendo conversa de cliente; só o e-mail daria acesso a uma conta rebaixada. O e-mail conferido é o gravado na conta, nunca o que o navegador manda.
- [x] **OS enviada vira mensagem na conversa** — inclusive quando o envio falha, com o motivo. A bolha mostra o número da OS que a originou.
- [x] **Encaminhamento da Ordem de Serviço** — a OS gerada ganhou três saídas além da impressão: e-mail para o endereço do cliente (já preenchido e editável na tela), WhatsApp do robô (a API Cloud da Meta envia sozinha) e "Meu WhatsApp", que abre o aplicativo do operador com o resumo e o link prontos.
- [x] **Assinatura da responsável técnica** — a rubrica digitalizada aparece sobre a linha de assinatura no documento, na impressão, no PDF, no e-mail e no link público.
- [x] **Link público da OS** — `api/os/view.php?id=…&t=…` mostra o documento em uma página própria, com botão de imprimir, para quem recebe a OS e não tem conta no dashboard. A autorização é a posse do token da linha (`share_token`, migration 014), comparado com `hash_equals`; id sem token, token errado e OS inexistente devolvem a mesma página 404.
- [x] **Trava de reenvio pelo robô** — o servidor recusa o segundo disparo de WhatsApp com `409 whatsapp_already_sent` e a tela abre a confirmação com a data e o número do envio anterior. A checagem é do servidor, não do navegador: duas abas ou um clique duplo mandavam a mesma OS duas vezes sem aviso.
- [x] **Registro dos envios** — `sent_at`/`sent_to` e `whatsapp_sent_at`/`whatsapp_sent_to` guardam o último envio de cada canal, exibidos na pré-visualização e na coluna "Envio" do histórico, com trilha em `activity_logs`.

### 🎨 Melhorias

- [x] **Suíte de testes hermética** — `ECOLETA_ENV_FILE=none` faz `db.php` ignorar o `public/api/env.php`, que o deploy gera com as credenciais de **produção**. Bastava um teste unitário incluir `db.php` para as constantes entrarem no processo inteiro: `AsaasLibTest`, escrito para "sem chave configurada", rodava com a chave real e chegava a bater na API do Asaas de verdade. Falhava só na máquina de quem tinha publicado, e passava na CI.
- [x] **`asaas_lib.php` usa `apiSecret()`** — carregava `env.php` por conta própria com `require` (sem `_once`), o que reincluía o arquivo a cada chamada e emitia um aviso "Constant already defined" por constante. Eram as 12 warnings da suíte.
- [x] **Listagem de OS restrita a administrador** — `api/os/index.php` aceitava qualquer sessão autenticada, embora a tela sempre tenha sido só de admin. Com o link de compartilhamento na resposta, um papel `user` — que no dashboard só enxerga o próprio painel — levaria de graça o acesso a todas as OS já emitidas.
- [x] **Desligamentos explícitos** — `MAIL_TRANSPORT=log` e `WHATSAPP_TRANSPORT=off` desligam e-mail e robô sem apagar credenciais. É o que impede a suíte de testes de mandar mensagem de verdade em uma máquina com o `env.php` de produção.
- [x] **`SITE_BASE_URL`** — a raiz do site usada no link com token deixa de depender do cabeçalho `Host`, que o cliente controla e que num e-mail enviado por nós apontaria o destinatário para outro servidor.
- [x] **Webhook do Asaas autenticado** — `api/webhooks/asaas.php` não conferia nada: um POST de três linhas (`{"event":"PAYMENT_RECEIVED","payment":{"id":"…"}}`) dava baixa em qualquer fatura para quem soubesse o id da cobrança, e uma fatura marcada como recebida sem pagamento some da régua de lembretes sem ninguém perceber. Passa a exigir o `ASAAS_WEBHOOK_TOKEN` no cabeçalho `asaas-access-token`, o mesmo que o painel do Asaas envia — e falha fechado quando não está configurado. Ganhou também o tratamento de estorno (`PAYMENT_REFUNDED`/`PAYMENT_DELETED`), que antes deixava a fatura "RECEIVED" para sempre.
- [x] **Cron de cobrança sem segredo embutido** — `api/cron/billing.php` caía em `'ecoleva_cron_secret'` quando `CRON_SECRET` não estava definido, um valor publicado neste repositório: qualquer pessoa podia emitir as cobranças do mês e disparar os e-mails. Agora falha fechado, compara com `hash_equals` e aceita o segredo no cabeçalho `X-Cron-Secret` — que é o que a documentação sempre prometeu e o que não fica gravado no log de acesso do servidor.
- [x] **Clientes e faturas restritos a administrador** — `api/clients/index.php`, `api/clients/edit.php` e `api/invoices/index.php` aceitavam qualquer sessão autenticada, embora as três telas desenhem "Acesso negado." para quem não é admin. Uma conta `user` lia a carteira inteira (nome, e-mail, WhatsApp, CPF/CNPJ e valor mensal), o histórico de cobranças com o Pix Copia e Cola de cada uma, e ainda cadastrava cliente e mudava o valor mensal de qualquer um. Mesma régua que a OS já usava.
- [x] **Token do WhatsApp vencido diz o que fazer** — a Meta responde "Authentication Error" (código 190) quando `WHATSAPP_ACCESS_TOKEN` expira, mensagem que não ajuda quem apertou o botão. A tela passa a receber o aviso de gerar um token permanente de Usuário do Sistema no Business Manager.

### 🐛 Correções

- [x] **E-mail de fatura sem link do boleto** — o botão "Visualizar Boleto" interpolava `{$faturaUrl}`, uma variável que nunca foi atribuída em `api/cron/billing.php`. Todo e-mail de cobrança e todo lembrete saíram com `href=""`: o cliente clicava e não ia a lugar nenhum. Acontecia nos dois e-mails porque o documento estava copiado em dois heredocs — agora é um só, em `api/billing_lib.php`, e o link vem do `invoiceUrl` que o Asaas devolve.
- [x] **Cliente com mensalidade e sem CPF/CNPJ nunca era faturado** — o Asaas aceita cadastrar o cliente sem documento, mas recusa gerar a cobrança ("Para criar esta cobrança é necessário preencher o CPF ou CNPJ do cliente"). Como o cron trata cada cliente dentro de um `try/catch` para não derrubar os outros, a recusa ia só para o `error_log` e o cliente simplesmente não era cobrado, mês após mês. Cadastro e edição passam a exigir o documento quando há valor mensal, e o cron registra no log o cadastro incompleto em vez de tentar e falhar.
- [x] **Número sem DDD ia quebrado para o banco** — `normalizePhone()` devolvia `"999887766"` para um WhatsApp digitado como `99988-7766`: nem a Meta aceita, nem o `wa.me` abre. Os dois lados do espelho (`lib/phone.ts` e `api/clients/phone_lib.php`) passam a completar com o DDD 21, e o campo se normaliza ao sair do foco.
- [x] **Formulário de clientes sem labels de verdade** — os rótulos não tinham `htmlFor`, então leitor de tela não associava rótulo e campo e clicar no texto não focava o campo.
- [x] **Busca da tela de conversas casava com tudo** — o termo era limpo para dígitos e comparado com o telefone, e `"5521999887766".includes("")` é verdadeiro: qualquer busca por texto trazia a lista inteira.
- [x] **Data da coleta um dia atrasada** — `new Date("2026-09-03")` é meia-noite UTC e voltava como 02/09/2026 em America/Sao_Paulo. A OS mostrava a coleta um dia antes do que foi digitado, o dia inteiro, para todo o Brasil. A formatação passou a ler a string ISO direto, em `lib/os-share.ts`.
- [x] **Histórico de OS desalinhado** — a tabela declarava quatro colunas e desenhava cinco células por linha; data e ações apareciam na coluna errada.

### 🔧 Técnico

**Migration 015:** `whatsapp_conversations` (número normalizado único, cliente ligado, não lidas, prévia da última mensagem e `service_window_expires_at`) e `whatsapp_messages` (direção, `wamid` único, status de entrega, corpo, payload cru e a OS que originou). As colunas de tempo são **DATETIME em UTC**, escritas e comparadas pelo PHP: `TIMESTAMP` é convertido pelo fuso da sessão do MySQL, que não é necessariamente o do PHP, e um servidor com os dois desalinhados abriria ou fecharia a janela três horas cedo demais, em silêncio. `ECOLETA_SCHEMA_VERSION` foi para 15.

**Migration 014:** `share_token` (único, com backfill das OS existentes), `sent_at`, `sent_to`, `whatsapp_sent_at` e `whatsapp_sent_to` em `service_orders`. `ECOLETA_SCHEMA_VERSION` foi para 14.

**Documento em um lugar só:** `public/api/os/os_lib.php` monta o HTML da OS que a página pública e o e-mail usam; `lib/os-share.ts` monta a mensagem de WhatsApp e as datas do dashboard. Os dois espelham o mesmo documento — mexeu em um, olhe o outro.

**Testes:** 76 casos novos no total. Para o WhatsApp: `WhatsAppStoreTest` trava a aritmética da janela (inclusive o empate no segundo do vencimento e a igualdade de fuso dos dois lados), `WhatsAppWebhookTest` cobre verificação da URL, assinatura inválida, reentrega, mídia e a progressão de status, `WhatsAppPanelTest` cobre as duas condições de acesso, e no front `tests/lib/whatsapp.test.ts` e `tests/app/whatsapp.test.tsx` cobrem a leitura da janela e a tela. A tabela de casos de `canViewWhatsAppPanel` é a mesma nos dois módulos espelhados.

**`billing_lib.php`:** as partes puras do faturamento saíram do cron — quando cobrar (`billingShouldIssue`, `billingShouldRemind`, `billingDueDate`) e o documento do e-mail (`billingNewInvoiceEmail`, `billingReminderEmail`). No cron ficou só o efeito colateral: segredo, banco, Asaas e envio. A separação nasceu de dois defeitos que a suíte não pegava porque nada executava o arquivo: o link do boleto vazio e testes de data que reimplementavam a regra dentro do próprio teste — e por isso continuariam verdes com o cron quebrado.

**Testes de faturamento:** 44 casos novos. `BillingLibTest` cobre a regra de data (uma emissão por mês, fevereiro comum e bissexto, dia 31), o vencimento que encolhe em mês curto e o documento do e-mail (botão do boleto presente e apontando para a fatura, ausência de `href=""`, escape do nome do cliente e do payload Pix). `AsaasWebhookTest` cobre as três recusas de autenticação e cada evento que muda status, inclusive o estorno. `BillingCronTest` deixou de reimplementar a regra de data e passou a cobrir só a porta de entrada — inclusive que o segredo antigo embutido no código não abre mais.

**Testes da OS:** 24 casos. Em PHP, `ServiceOrderShareTest` cobre a criação com token, o link público (aceito, recusado e sem token), o e-mail e as recusas do robô. No front, `tests/lib/os-share.test.ts` e `tests/app/os.test.tsx` cobrem a formatação de data, a mensagem do WhatsApp e o fluxo de confirmação de reenvio.

## [v1.3.0]

### 🐛 Correções

- [x] **Faturamento Automático (Cron)** — corrigida duplicação de faturas em meses de 31 dias e implementada idempotência na geração de pagamentos Asaas.
- [x] **Integração Asaas** — sincronização do WhatsApp na API do Asaas habilitada durante a edição do cliente.
- [x] **Normalização de WhatsApp** — corrigida regex para preservar o zero correto e normalizar os 14 dígitos (DDI + DDD) no padrão aceito pelo Asaas.
- [x] **Segurança e Erros** — impedida conversão incorreta (TypeError) no PHP 8 e mascaramento de exceções críticas no cadastro de empresas.
- [x] **Perfil de Usuário** — bloqueada tentativa silenciosa de sobrepor um e-mail já existente na tela de troca de senha inicial.


### ✨ Novidades

- [x] **E-mail opcional no cadastro de usuário** — a criação passa a exigir só o login; o e-mail em branco é pedido no primeiro acesso, junto da definição de senha (`auth/change_password.php` aceita e valida o e-mail quando a conta ainda não tem um). Login continua funcionando por login **ou** e-mail.
- [x] **Coluna de último login** — a gestão de usuários mostra o último acesso de cada conta (via `access_logs`), com "Nunca acessou" para quem ainda não entrou.
- [x] **Vencimento e status de cobrança por cliente** — cada cliente ganhou dia de vencimento (`due_day`, padrão 10) e status ativo/inativo (migration 011). A fatura mensal passa a vencer no dia configurado de cada cliente, ajustado para meses curtos, e o cron de cobrança só fatura cliente **ativo** com **valor mensal positivo**. Novo `api/clients/edit.php` atualiza valor, vencimento e status; o dashboard de clientes exibe as duas colunas e alterna o status direto na tabela.
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
- [x] **Build empacotado fora do versionamento** — `ecoleta-out.zip` respondia por 143 MB do histórico, em três blobs: 78% de tudo que o repositório carrega, contra 4,8 MB de todo o código do projeto somado. É o export estático que `npm run build` regera em `out/`, conferido entrada por entrada contra uma build limpa — nenhuma das 501 existia só ali, e o pacote ainda estava velho o bastante para derrubar o dashboard se alguém publicasse a partir dele. Saiu do rastreamento; os blobs seguem no histórico até a reescrita proposta em `docs/peso-do-repositorio.md`.

### 🐛 Correções

- [x] **Indicadores editáveis corrigidos** — a tela de Configurações → Indicadores editava os cinco cards qualitativos da home, mas os números que a cliente precisa ajustar são os seis da seção "Números reais da nossa operação" da página ESG. A migration 012 troca o conteúdo de `site_indicators` pelos seis números (pessoas, CO₂, energia, árvores, carros, água), a seção da ESG passa a ler do banco com fallback nos valores atuais, e os cards da home voltaram a ser conteúdo estático.
- [x] **Suíte PHP destravada** — o espelho SQLite de `tests/php/Support/TestDatabase.php` tinha parado na versão 5 do schema enquanto as migrations iam até a 10: a `schema_migrations` de teste ficava atrás de `ECOLETA_SCHEMA_VERSION`, todo endpoint sob teste respondia 503 antes de chegar na regra testada e 30 casos quebravam. O espelho agora reproduz a v11, com as tabelas das migrations 006–009.
- [x] **Deploy preserva os segredos do Asaas** — `scripts/deploy-ftp.sh` regenerava `api/env.php` sem `ASAAS_API_KEY` nem `CRON_SECRET`, apagando do servidor qualquer chave configurada; os dois agora são propagados do `.env` como as demais variáveis.
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
- [x] `.gitignore` passou a cobrir `*.zip` e os demais formatos de pacote, além de `/graphify-out/`. Até aqui a proteção vinha do `~/.gitignore_global` do dono, que existe só na máquina dele: em outro clone, ou num runner de CI, um `git add .` recolocaria os 76 MB no commit.
- [x] `docs/peso-do-repositorio.md` — inventário medido sobre o histórico inteiro (não só sobre o diretório de trabalho), o trade-off dos vídeos do hero e a proposta de reescrita. Os números vêm de clones descartáveis: 185 MB caem para 41 MB removendo só o zip, 14 MB removendo também os vídeos. A reescrita fica **proposta, não executada** — o peso está em `origin`, e reescrever só localmente deixaria o clone divergente para sempre sem tirar um byte do GitHub.
- [x] Duas armadilhas medidas para quem for executar a reescrita: quatro refs `refs/codex/turn-diffs/checkpoints/*` apontam para objetos *tree*, que o `git-filter-repo` pula — e uma delas segura sozinha 36 MB do zip, deixando o resultado em 77 MB em vez de 41 MB; e o `git-filter-repo` 2.47.0 quebra ao ler os aliases multilinha do `.gitconfig` global, contornado com `GIT_CONFIG_GLOBAL=/dev/null`.
- [x] Os três zips do histórico foram conferidos e **nenhum contém `out/api/env.php`**, que o deploy gera com as credenciais de produção em texto puro. O arquivo é ignorado pela regra `/out/`, mas um zip de `out/` não era — e foi um zip de `out/` que entrou no histórico três vezes.

## [v1.2.0 (2026-08-21)](https://github.com/ricardosierra/ecoleta/compare/v1.0.4...v1.2.0)

### 🐛 Correções

- [x] **Faturamento Automático (Cron)** — corrigida duplicação de faturas em meses de 31 dias e implementada idempotência na geração de pagamentos Asaas.
- [x] **Integração Asaas** — sincronização do WhatsApp na API do Asaas habilitada durante a edição do cliente.
- [x] **Normalização de WhatsApp** — corrigida regex para preservar o zero correto e normalizar os 14 dígitos (DDI + DDD) no padrão aceito pelo Asaas.
- [x] **Segurança e Erros** — impedida conversão incorreta (TypeError) no PHP 8 e mascaramento de exceções críticas no cadastro de empresas.
- [x] **Perfil de Usuário** — bloqueada tentativa silenciosa de sobrepor um e-mail já existente na tela de troca de senha inicial.


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
