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

## Checklist

- [ ] `.env` com `DB_*`, `DB_DDL_*` e `FTP_*` preenchidos
- [ ] `php db/migrate.php status` mostra o banco na versão esperada
- [ ] `php db/migrate.php migrate` sem erro
- [ ] `npm run build` e `npm run lint` limpos
- [ ] `npm run deploy:ftp`
- [ ] `/dashboard` abre e o login funciona
- [ ] `DASHBOARD_INSTALL_TOKEN` vazio no `.env` (fora da instalação inicial)
