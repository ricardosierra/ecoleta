# Deploy do dashboard — migrations e publicação

> Documento técnico do repositório (não é material da cliente).

O site é estático, mas o dashboard em `/dashboard` fala com um punhado de
endpoints PHP em `public/api/` que dependem de um schema MySQL. Este documento
descreve como esse schema é criado e em que ordem tudo sobe.

## A regra

**Migrations primeiro, arquivos depois.**

```
1. php db/migrate.php migrate     # por SSH, com o usuário de DDL
2. npm run deploy:ftp             # publica out/ por FTP
```

A ordem não é arbitrária. Entre os dois passos o banco fica alguns minutos à
frente do código, e a API tolera isso de propósito: colunas a mais não
atrapalham quem não as consulta. Na ordem inversa, os arquivos novos consultam
colunas que ainda não existem — o dashboard responde `503` até alguém lembrar da
migration, e o erro aparece como falha de SQL solta no log.

`scripts/deploy-ftp.sh` pergunta antes de publicar. Em execução não interativa
(CI), confirme com `MIGRATIONS_APPLIED=1 npm run deploy:ftp`.

## Por que o schema saiu do request

Até a v1.2.0, `getDbConnection()` chamava `ensureTablesExist()`: **toda**
requisição autenticada rodava cinco criações de tabela, uma inspeção das colunas
de `users`, uma alteração condicional dessa tabela e duas consultas de seed. O
guard `static` só valia dentro do processo PHP, que morre no fim do request,
então o custo se repetia sempre.

Três problemas, em ordem de gravidade:

1. **Privilégio.** O usuário MySQL da aplicação — aquele cuja senha está dentro
   do webroot, em `public/api/env.php` — precisava de permissão de DDL. Quem
   conseguisse ler esse arquivo ganhava `DROP TABLE` junto.
2. **Metadata lock.** DDL em MySQL pega metadata lock na tabela. Sob
   concorrência, uma alteração disparada por requisição vira contenção; sob
   carga, vira fila.
3. **Alteração automática de tabela.** Um `ALTER TABLE` que dispara sozinho, sem
   ninguém olhando, é a definição de mudança não supervisionada em produção.

## Usuários MySQL

Dois usuários, com permissões diferentes.

### Usuário da aplicação (`DB_USER`)

É o que o PHP servido pela web usa. Só precisa de DML:

```sql
CREATE USER 'ecoleta_app'@'localhost' IDENTIFIED BY 'senha-forte-aqui';
GRANT SELECT, INSERT, UPDATE, DELETE
  ON `ecoleta`.* TO 'ecoleta_app'@'localhost';
FLUSH PRIVILEGES;
```

Sem `CREATE`, sem `ALTER`, sem `DROP`, sem `INDEX`, sem `REFERENCES`. Se um dia
a aplicação voltar a precisar de DDL para funcionar, é bug — não motivo para
alargar o `GRANT`.

### Usuário de migration (`DB_DDL_USER`)

Só é usado por `php db/migrate.php`, por SSH. A senha dele **nunca** entra em
`public/api/env.php`.

```sql
CREATE USER 'ecoleta_ddl'@'localhost' IDENTIFIED BY 'outra-senha-forte';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
  ON `ecoleta`.* TO 'ecoleta_ddl'@'localhost';
FLUSH PRIVILEGES;
```

> **Hospedagem compartilhada.** Painéis como o da Hostinger nem sempre deixam
> criar um segundo usuário ou ajustar `GRANT` por comando. Quando não der, o
> runner cai em `DB_USER`/`DB_PASS` e avisa — funciona, mas mantém o privilégio
> de DDL na credencial que fica dentro do webroot. Trate como pendência, não
> como configuração final.

Configure ambos no `.env` local (ver `.env.example`). O deploy grava em
`public/api/env.php` apenas o par da aplicação.

## O runner

```bash
php db/migrate.php status              # o que já foi aplicado e o que falta
php db/migrate.php migrate             # aplica as pendentes
php db/migrate.php migrate --dry-run   # mostra o que rodaria, sem executar
php db/migrate.php --help
```

Ele recusa rodar fora do CLI: sob SAPI web responde 404 e sai. E `db/` não é
publicado — o deploy sobe apenas `out/`.

Configuração, em ordem de precedência:

1. variáveis de ambiente reais (`DB_HOST=... php db/migrate.php`)
2. `public/api/env.php`, se existir no checkout
3. `.env` na raiz (ou `--env-file=CAMINHO`)

### O registro

`schema_migrations` guarda uma linha por migration aplicada: versão, arquivo,
checksum SHA-256, número de instruções, duração e data. É ela que responde "este
banco está em que versão" — para o runner e para a API.

```sql
SELECT version, filename, applied_at FROM schema_migrations ORDER BY version;
```

### Testes

O separador de instruções do runner é a peça mais arriscada: um ponto e vírgula
lido dentro de uma string quebraria a migration ao meio e aplicaria SQL cortado.
Ele, a substituição de placeholder e o leitor de `.env` têm teste sem banco:

```bash
npm run test:migrations      # php db/tests/migrate_test.php
```

### Idempotência

MySQL faz commit implícito em cada DDL: **não existe rollback de migration**. Uma
migration que quebra na terceira instrução deixa as duas primeiras aplicadas e
não é registrada — rodar de novo repete as três. Por isso toda instrução em
`db/migrations/` é escrita para poder rodar duas vezes sem estragar nada
(`CREATE TABLE IF NOT EXISTS`, `INSERT ... WHERE NOT EXISTS`, `ALTER` guardado
por consulta ao `information_schema`).

Isso também é o que permite aplicar as migrations num banco que já existe: o
banco de produção já tem todas as tabelas, criadas pelo `db.php` antigo. A
primeira execução do runner registra as cinco versões sem alterar nada.

## Adicionando uma migration

1. Crie `db/migrations/006_descricao_curta.sql` — numeração de três dígitos,
   sequencial, `snake_case`.
2. Escreva instruções idempotentes.
3. Suba `ECOLETA_SCHEMA_VERSION` em `public/api/schema.php` para `6`.
4. `php db/migrate.php migrate --dry-run` e depois `migrate`.

O passo 3 não é opcional: o runner compara a constante com a última migration em
disco e **recusa terminar** se divergirem. É essa constante que a API usa para
decidir entre servir e responder 503.

**Migration aplicada é imutável.** Editar o arquivo depois muda o checksum, e o
runner para com a instrução de como resolver. Para mudar o schema, crie a
próxima migration.

## Quando a API responde 503

Resposta com `"code": "schema_out_of_date"` significa que o banco está atrás do
código. O motivo detalhado fica no log de erro do PHP — a resposta não conta ao
cliente em que versão o banco está. Procure por:

```
Schema do banco fora de dia (banco na versão 4, código exige 5 — faltam 1 migration(s))
```

Correção: rodar `php db/migrate.php migrate`. Se a tabela `schema_migrations` não
existe, o banco nunca foi migrado e a mesma correção vale.

O caminho inverso — banco à frente do código — não derruba nada: gera só uma
linha de log. É o estado normal entre os passos 1 e 2 do deploy. Se persistir
depois do deploy terminar, o upload dos arquivos ficou pela metade.

## Primeira instalação

Depois das migrations e do primeiro deploy, o banco está com o schema pronto e
sem nenhum usuário. Crie o `root` uma única vez por `api/install.php`, com o
`DASHBOARD_INSTALL_TOKEN` definido no `.env`:

```bash
curl -X POST https://SEU-DOMINIO/api/install.php \
     -H "X-Install-Token: <valor de DASHBOARD_INSTALL_TOKEN>" \
     -H "Content-Type: application/json" \
     -d '{"login":"admin","email":"admin@exemplo.com"}'
```

A senha volta na resposta uma única vez. Troque-a no primeiro acesso, apague
`DASHBOARD_INSTALL_TOKEN` do `.env` e refaça o deploy — sem o token o endpoint
responde 404.

## Webhook do WhatsApp

A janela de 24 horas — que decide se o envio da OS é gratuito ou cobrado — só
existe se a Meta conseguir nos entregar as mensagens recebidas. Sem o webhook
cadastrado, `service_window_expires_at` nunca é preenchida, o botão do robô fica
sempre neutro e todo envio cai no caminho do template.

Ordem, uma vez só:

1. No `.env`, defina `WHATSAPP_WEBHOOK_VERIFY_TOKEN` (valor livre, por exemplo
   `php -r "echo bin2hex(random_bytes(24));"`) e `WHATSAPP_APP_SECRET` (o App
   Secret do app, em Configurações > Básico no painel da Meta).
2. Publique (`npm run deploy:ftp`) para o `api/env.php` do servidor receber os
   dois valores. A URL precisa existir **antes** de a Meta tentar verificá-la.
3. No painel da Meta, em WhatsApp > Configuração, cadastre o callback:
   - URL: `https://<dominio>/api/webhooks/whatsapp.php`
   - Token de verificação: o mesmo `WHATSAPP_WEBHOOK_VERIFY_TOKEN`
   - Campo assinado: `messages`
4. Confirme que a verificação passou. A Meta faz um `GET` com `hub.challenge` e
   espera o valor de volta em texto puro; token errado devolve 403 e o motivo
   fica no log do servidor.

O `WHATSAPP_APP_SECRET` não é opcional: o endpoint recusa (503) todo evento
enquanto ele estiver vazio. É ele que prova que o corpo veio da Meta — sem essa
conferência, qualquer um na internet abriria a janela de 24 horas mandando uma
mensagem inventada de cliente.

## Faturamento automático (cron)

As cobranças mensais só saem se alguém chamar `api/cron/billing.php` **uma vez por
dia**. O arquivo não se agenda sozinho: o agendamento mora no painel de Cron Jobs
da hospedagem. Enquanto ele não existir, nenhum cliente com valor mensal recebe
fatura. A auditoria de 05/09 já registrava que o agendamento nunca foi verificado.

O que cada execução faz. Ela é idempotente: rodar duas vezes no dia, ou perder um
dia, não duplica nem esquece nada.

- Garante a fatura de cada cliente **ativo** com valor mensal: a do mês corrente
  enquanto o vencimento não passou (hoje inclusive) e a do mês seguinte a partir do
  dia 30 (ou do último dia do mês, em fevereiro). Nunca emite data passada.
- Uma fatura do cliente naquele mês, de qualquer data e de qualquer status
  (inclusive cancelada), conta como a do mês: nada é emitido em duplicidade.
- Nos dias 3 e 7 reenvia o lembrete do que continua pendente ou vencido. A fatura
  emitida há menos de 20 horas não recebe lembrete.
- Grava o registro da execução (`billing_cron_run` em `activity_logs`). A tela de
  Faturas mostra o aviso "Faturamento automático" acima do formulário e avisa quando
  o cron nunca rodou ou parou há mais de 36 horas.

### Agendando

Uma chamada por dia, em qualquer horário. A data é calculada em
`America/Sao_Paulo`, então só evite os minutos em volta da meia-noite de Brasília.
Escolha a forma que o painel permitir:

```bash
# HTTP. O cabeçalho mantém o segredo fora do log de acesso; -f faz o curl falhar
# em 4xx/5xx, o que o painel costuma avisar por e-mail.
curl -fsS -H "X-Cron-Secret: <valor de CRON_SECRET>" https://<dominio>/api/cron/billing.php

# CLI, no próprio servidor. Não precisa do cabeçalho, mas CRON_SECRET continua
# exigido no api/env.php.
php /caminho/da/conta/public_html/api/cron/billing.php
```

Frequência diária. Exemplo: `0 11 * * *` se o relógio do servidor for UTC (08:00 em
Brasília) ou `0 8 * * *` se for o de Brasília. Confirme o fuso do painel antes.

### Respostas

| Resposta | Significa |
| --- | --- |
| `200`, "Cron rodou com sucesso" | Rodou e nada falhou (pode ter gerado zero faturas) |
| `502` com JSON em `errors` | Rodou, mas algum cliente falhou: cadastro incompleto, Asaas fora, envio recusado, cliente sem e-mail e sem WhatsApp |
| `503` "CRON_SECRET não configurado" | `CRON_SECRET` vazio no `api/env.php`; o cron se recusa a rodar |
| `403` "Acesso negado" | Segredo diferente do configurado |

O que falhou é tentado de novo na execução do dia seguinte, até o vencimento.

### Antes do primeiro disparo

A primeira execução **emite e envia por e-mail e WhatsApp** as faturas que estiverem
faltando: todo cliente ativo com valor mensal e vencimento ainda por vir neste mês.
Confira a lista de clientes (valor, dia e contatos) antes de disparar e não use
cliente de teste com e-mail ou telefone de gente real. Depois de agendar, abra
`/dashboard/faturas`: o aviso passa a mostrar a última execução.

### Arquivos temporários no servidor

O deploy por FTP só envia arquivos: **nunca apaga** o que saiu do repositório. Um
script removido do Git continua respondendo na hospedagem até alguém apagá-lo pelo
gerenciador de arquivos. Depois de remover qualquer arquivo de `public/`, apague-o
do servidor também. Confira especialmente `api/temp_reset.php` e
`api/migrate_temp.php`, que já estiveram no repositório.

## Checklist

- [ ] `.env` com `DB_*`, `DB_DDL_*` e `FTP_*` preenchidos
- [ ] `php db/migrate.php status` mostra o banco na versão esperada
- [ ] `php db/migrate.php migrate` sem erro
- [ ] `npm run build` e `npm run lint` limpos
- [ ] `npm run deploy:ftp`
- [ ] `/dashboard` abre e o login funciona
- [ ] `DASHBOARD_INSTALL_TOKEN` vazio no `.env` (fora da instalação inicial)
- [ ] `SITE_BASE_URL` apontando para o domínio de produção
- [ ] Webhook do WhatsApp verificado no painel da Meta (ver acima)
- [ ] Cron diário do faturamento agendado e visível na tela de Faturas (ver acima)
- [ ] `api/temp_reset.php` e `api/migrate_temp.php` não existem no servidor
