# CLAUDE.md — Orientação para agentes de IA

> Este arquivo é lido automaticamente pelo Claude Code e por outros agentes de IA ao trabalhar neste repositório. Mantenha-o curto e atualizado.

## O projeto

Site institucional da **Ecoleta** — empresa de gestão de resíduos com foco em rastreabilidade, conformidade ambiental e ESG. Cliente: Ecoleta + Econformidade (braço tecnológico).

Referência visual oficial da cliente: <https://impactacomvoce.com.br/>.

## Stack

- **Next.js 16** (App Router, Turbopack)
- **React 19** + **TypeScript** (strict)
- **Tailwind CSS v4** (config inline via `@theme {}` no `globals.css`)
- **Resend** para envio de e-mail (fallback SMTP via Nodemailer opcional)
- **Zod** para validação do formulário de contato
- Sem state manager, sem UI lib externa — componentes próprios

## Comandos

```bash
npm run dev            # http://localhost:3000
npm run build          # produção (deve passar limpo)
npm run lint           # ESLint (deve passar limpo)
npm run typecheck      # tsc --noEmit (deve passar limpo)
npm test               # Vitest — front
npm run test:php       # PHPUnit — public/api/, com SQLite descartável
npm run test:migrations # partes puras de db/migrate.php
npm start              # servir build
```

Regra de papel (`root`/`master`/`user`) mora em dois módulos espelhados:
`lib/authz.ts` no cliente, só para escolher o que desenhar, e
`public/api/authz.php` no servidor, que é o lado que decide. Mexeu em um,
mexa no outro — e nos dois arquivos de teste, que exercitam a mesma tabela
de casos de propósito.

A Ordem de Serviço tem o mesmo tipo de espelho: `public/api/os/os_lib.php`
monta o documento HTML da página pública e do e-mail; `lib/os-share.ts` monta
a mensagem de WhatsApp e as datas do dashboard. Os dois entregam o mesmo
documento ao cliente — mexeu em um, olhe o outro.

O painel de WhatsApp (`/dashboard/whatsapp`) só abre para `root` **e** e-mail na
lista de `apiRoleCanViewWhatsAppPanel()` (authz.php) / `canViewWhatsAppPanel()`
(authz.ts). A lista está no código nos dois lados de propósito: um valor de env
não atravessa para o navegador, e sem a regra no cliente o menu desenharia um
link que a API recusa.

## Estrutura

```
app/                       Rotas (App Router)
  page.tsx                 Home
  solucoes/page.tsx        Página Soluções
  esg/page.tsx             Página ESG e Impacto
  cases/page.tsx           Página Cases & Provas
  contato/page.tsx         Sobre + Contato (com formulário)
  api/contact/route.ts     Endpoint do formulário
  sitemap.ts · robots.ts   SEO
  layout.tsx · globals.css

components/                Componentes reutilizáveis (16)
  Header · Footer · WhatsAppFloatingButton
  Button · Card · Section · PageHero · Reveal
  ContactForm · DonutChart · MetricCard
  ProcessSteps · LogoCarousel · DynamicWord · Gallery
  Logo · icons.tsx

lib/                       Utilitários e configuração
  site.config.ts           URLs, contatos, navegação (com placeholders)
  contact-schema.ts        Schema Zod do formulário
  os-share.ts              Mensagem de WhatsApp e datas da OS
  whatsapp.ts              Janela de 24h e formatação do painel
  phone.ts                 Normalização de telefone (espelho de phone_lib.php)
  rate-limit.ts            Rate limit em memória (5 req/min/IP)
  cn.ts                    Helper para classes condicionais

db/                        Schema do banco (nunca publicado pelo deploy)
  migrate.php              Runner CLI das migrations
  migrations/*.sql         DDL e seed versionados

docs/                      Documentação do projeto (NÃO MEXER nos arquivos da cliente)
  deploy.md                Ordem do deploy e usuários MySQL (doc técnico, editável)
  briefing.md              Briefing original
  estrutura-site.md         Estrutura das 5 abas
  identidade-visual.md      Tokens consolidados
  referencia-impacta.md     Análise CSS do site de referência
  conversa-claude.md        Conversa cliente↔Claude AI
  visual-references/        6 prints aprovados
  transcricoes/             Áudios transcritos
  PORTFOLIO ECOLETA.pdf     Portfólio com clientes (logos)
```

## Convenções importantes

### Design tokens (em `app/globals.css`)

Sempre usar as variáveis CSS do `@theme {}` — não criar cores/sombras/radius hardcoded. Tokens principais:

```css
--color-bg-dark:   #0D1F0F   /* hero, seções escuras */
--color-bg-light:  #ECF5FB   /* seções claras */
--color-accent:    #7ED957   /* destaques, ícones, dados */
--color-secondary: #2D5934   /* CTAs secundários */
--color-text:      #242424
--color-text-muted:#5A5A5A

--radius-button:   50px      /* pílula total — ASSINATURA do estilo */
--radius-card:     10px
--container-max:   1140px
```

Tipografia: **Montserrat** apenas (400/500/600/700). Não introduzir outras fontes.

### Padrões de componente

- **Sections** alternam `tone="dark"` / `tone="light"` / `tone="white"` / `tone="accent"`. Use `<Section>` em vez de criar `<section>` com classes ad-hoc.
- **Botões** sempre via `<Button>` (5 variantes, 3 tamanhos). Mantém o radius pílula consistente.
- **Eyebrows** (rótulos pequenos acima do título) seguem o utilitário `.eyebrow` ou `<Eyebrow>`. Sempre uppercase com tracking ampliado.
- **Animações** via `<Reveal>` — degradação progressiva (renderiza visível por padrão, anima só com JS+IO+sem reduced-motion).

### WhatsApp

- **A janela de 24 horas manda no custo.** Dentro dela (o cliente escreveu para
  o nosso número há menos de 24h) o envio é texto livre e a Meta não cobra;
  fora, só template aprovado, e é tarifado. Quem guarda o prazo é
  `whatsapp_conversations.service_window_expires_at`, empurrada pelo webhook a
  cada mensagem recebida.
- **Tempo do WhatsApp é DATETIME em UTC**, escrito e comparado pelo PHP
  (`waNow()`, `waWindowIsOpen()`). Não use `TIMESTAMP` nem `NOW()` nessas
  tabelas: o fuso da sessão do MySQL não é necessariamente o do PHP, e três
  horas de diferença abrem ou fecham a janela na hora errada, em silêncio.
- O webhook (`api/webhooks/whatsapp.php`) é público e **exige**
  `WHATSAPP_APP_SECRET`: sem assinatura conferida, qualquer um abriria a janela
  inventando mensagem de cliente.
- `WHATSAPP_TRANSPORT=off` e `MAIL_TRANSPORT=log` desligam robô e e-mail sem
  apagar credencial. A suíte liga os dois — nenhum teste pode encostar em rede.

### Faturamento

- Emissão e entrega compartilhadas pelo cron e pela tela de faturas vivem em
  `public/api/billing_delivery.php`. Trava MySQL por cliente/vencimento evita
  concorrência; `externalReference` recupera pagamentos externos após interrupção.
  O histórico de entrega por canal usa `activity_logs` (`billing_attempt`,
  `billing_delivery`, `billing_failed`). Tentativa interrompida não deve ser
  reenviada automaticamente sem conferir o provedor.
- `invoices.pix_qrcode_url` é VARCHAR(255): **não gravar encodedImage/base64**.
  O Pix copia e cola fica em `pix_qrcode_text`; falha ao obter Pix não pode
  descartar uma cobrança já criada no Asaas.

- **O que decide e o que desenha mora em `public/api/billing_lib.php`** — quando
  cobrar (`billingShouldIssue`, `billingDueDate`) e o documento do e-mail
  (`billingNewInvoiceEmail`, `billingReminderEmail`). `api/cron/billing.php` fica
  só com o efeito colateral. Foi assim que o link do boleto vazio passou
  despercebido: com o HTML copiado dentro do cron, nenhum teste conseguia
  renderizar o e-mail.
- **Cliente com `monthly_value > 0` precisa de CPF/CNPJ.** O Asaas cadastra o
  cliente sem documento e recusa a COBRANÇA — recusa que só aconteceria no dia
  30, dentro do `try/catch` do cron, no `error_log`. Cadastro e edição barram
  antes; o cron registra o cadastro incompleto em vez de tentar.
- **Os dois webhooks falham fechados.** `api/webhooks/asaas.php` exige
  `ASAAS_WEBHOOK_TOKEN` no cabeçalho `asaas-access-token`; `api/cron/billing.php`
  exige `CRON_SECRET` (cabeçalho `X-Cron-Secret`, ou `?secret=` por
  compatibilidade). Sem o segredo configurado, nenhum dos dois roda — dar baixa
  em fatura ou emitir as cobranças do mês não pode depender de ninguém adivinhar
  que a variável ficou vazia.
- Clientes, faturas e OS são módulos de **administrador nos dois lados**: a tela
  desenha "Acesso negado." e a API responde 403. São dados de carteira e de
  cobrança.

### Banco de dados

- **Nenhum DDL no caminho do request.** `getDbConnection()` só conecta. Tabelas
  e seed vivem em `db/migrations/`, aplicadas por `php db/migrate.php` via
  SSH/CLI antes do deploy dos arquivos.
- Ao adicionar uma migration, subir `ECOLETA_SCHEMA_VERSION` em
  `public/api/schema.php` junto — o runner recusa terminar se divergirem.
- Migration já aplicada é imutável (checksum registrado). Para mudar o schema,
  criar a próxima.
- O usuário MySQL da aplicação tem só `SELECT/INSERT/UPDATE/DELETE`. DDL usa
  `DB_DDL_USER`, que nunca entra em `public/api/env.php`. Ver `docs/deploy.md`.

### Formulário e e-mail

- Validação **dupla** (Zod no client + server). Backend é a fonte de verdade.
- Honeypot (`website`) — bot recebe `200 OK` silencioso, sem feedback.
- Rate limit em memória, 5 req/min/IP. Para escalar, trocar `lib/rate-limit.ts` por Redis/Upstash.
- Endpoint tenta Resend → SMTP → modo dev (apenas log). Configurar via env (ver `.env.example`).
- **Nunca** logar PII em produção. **Nunca** expor variáveis sem prefixo `NEXT_PUBLIC_` no client.

### Acessibilidade

- HTML semântico obrigatório (h1/h2/h3 corretos, listas em `<ul>`, formulário com labels reais e `aria-invalid`).
- Focus ring global definido em `globals.css` — não remover.
- `prefers-reduced-motion` deve ser respeitado em qualquer nova animação.
- `aria-label` em todos os botões com ícone-só.

### O que NÃO fazer

- ❌ **Não introduzir bibliotecas de UI** (shadcn, Material, Chakra). Manter componentes próprios.
- ❌ **Não criar arquivos `.md` em `docs/`** sem pedido explícito. Conteúdo da cliente é canônico.
- ❌ **Não usar Keep-a-Changelog** se for criar `CHANGELOG.md`. Usar formato "Release Notes" (ver `~/.claude/CLAUDE.md`).
- ❌ **Não trocar Montserrat** por outra fonte sem realinhar com a cliente.

## Placeholders a substituir antes do go-live

Tudo via `.env.local` (copiar de `.env.example`):

- `NEXT_PUBLIC_WHATSAPP_NUMBER` — formato internacional sem `+` (ex: `5511999999999`)
- `NEXT_PUBLIC_INSTAGRAM_URL`, `NEXT_PUBLIC_LINKEDIN_URL`
- `NEXT_PUBLIC_CNPJ`, `NEXT_PUBLIC_ADDRESS`
- `CONTACT_TO_EMAIL`, `RESEND_API_KEY` (ou bloco `SMTP_*`)
- `SITE_BASE_URL` — **fixar em produção**: em branco, o link com token da OS é
  montado a partir do cabeçalho `Host`, que quem chama controla
- `ASAAS_WEBHOOK_TOKEN` — o mesmo valor do campo "Token de autenticação" no
  painel do Asaas; sem ele o webhook recusa todo evento
- `WHATSAPP_ACCESS_TOKEN` — precisa ser um token **permanente** de Usuário do
  Sistema (Business Manager). O que a Meta oferece na primeira tela do painel
  vence em 24 horas e derruba o robô sem aviso
- `WHATSAPP_OS_TEMPLATE` — em branco, o robô só entrega dentro da janela de 24h

Conteúdo dinâmico que precisa ser editado no código:

- **Logos de clientes** em `app/cases/page.tsx` — atualmente um array de placeholders
- **Galeria** em `app/cases/page.tsx` — atualmente cards estilizados; trocar por `<Image>` reais
- **Indicadores percentuais** (ex: 68% desvio de aterro) — confirmar valores reais com a cliente

## Domínio alvo

`https://ecolevaeco.com/` (em migração — atualmente `econformidade.com.br`).
E-mail em migração: `@econformidade` → `@ecolevaeco`.
