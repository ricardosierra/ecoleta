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
npm run dev      # http://localhost:3000
npm run build    # produção (deve passar limpo)
npm run lint     # ESLint (deve passar limpo)
npm start        # servir build
```

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

Conteúdo dinâmico que precisa ser editado no código:

- **Logos de clientes** em `app/cases/page.tsx` — atualmente um array de placeholders
- **Galeria** em `app/cases/page.tsx` — atualmente cards estilizados; trocar por `<Image>` reais
- **Indicadores percentuais** (ex: 68% desvio de aterro) — confirmar valores reais com a cliente

## Domínio alvo

`https://ecolevaeco.com/` (em migração — atualmente `econformidade.com.br`).
E-mail em migração: `@econformidade` → `@ecolevaeco`.
