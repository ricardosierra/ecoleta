# Ecoleta

Site institucional da **Ecoleta** — empresa de gestão de resíduos com foco em rastreabilidade, conformidade ambiental e impacto ESG real.

## Stack

- **Next.js 16** (App Router)
- **React 19** + **TypeScript**
- **Tailwind CSS v4**
- **Resend** (envio de e-mail) com fallback para SMTP via Nodemailer
- **Zod** (validação do formulário)

## Estrutura

```
app/
  ├ page.tsx              ← Home
  ├ solucoes/page.tsx     ← Soluções
  ├ esg/page.tsx          ← ESG e Impacto
  ├ cases/page.tsx        ← Cases & Provas
  ├ contato/page.tsx      ← Sobre + Contato (com formulário)
  ├ api/contact/route.ts  ← Endpoint de envio do formulário
  ├ sitemap.ts · robots.ts · layout.tsx · globals.css
components/
  ├ Header · Footer · WhatsAppFloatingButton
  ├ Button · Card · Section · PageHero · Reveal
  ├ ContactForm · DonutChart · MetricCard
  ├ ProcessSteps · LogoCarousel · DynamicWord · Gallery
  ├ Logo · icons.tsx
lib/
  ├ site.config.ts        ← URLs, contatos, navegação (placeholders)
  ├ contact-schema.ts     ← Schema Zod do formulário
  ├ rate-limit.ts         ← Rate limit em memória
  └ cn.ts
```

## Setup

```bash
npm install
cp .env.example .env.local   # editar com dados reais
npm run dev                   # http://localhost:3000
```

### Variáveis de ambiente

Ver [`.env.example`](./.env.example) — separadas em duas categorias:

- **`NEXT_PUBLIC_*`** — exibidas no site (WhatsApp, Instagram, CNPJ, endereço, e-mail).
- **Server-only** — credenciais de e-mail (Resend ou SMTP) e banco de dados, nunca expostas no client.

### Banco de Dados (Hostinger / Expansões Futuras)

Banco MySQL criado na Hostinger para integrar funcionalidades futuras (ex: persistência de cadastros ou auditoria):

- Preencha `DB_HOST`, `DB_NAME`, `DB_USER` e `DB_PASS` apenas no arquivo `.env` local.

### Dashboard (`/dashboard`)

O backend do dashboard são os arquivos PHP em `public/api/`, publicados junto com o
export estático. Não há Composer nem dependências externas: PHP 8 e MySQL.

**Primeiro acesso — instalação única.** Não existe usuário nem senha padrão: sem
`DASHBOARD_INSTALL_TOKEN` definido, `api/install.php` responde 404 e nenhum root é
criado. Para instalar:

```bash
# 1. gere o token e coloque em DASHBOARD_INSTALL_TOKEN no .env (mínimo 24 caracteres)
php -r "echo bin2hex(random_bytes(32));"

# 2. publique (npm run deploy:ftp) e crie o root uma única vez
curl -X POST https://SEU-DOMINIO/api/install.php \
     -H "X-Install-Token: <o token gerado>" \
     -H "Content-Type: application/json" \
     -d '{"login":"admin","email":"admin@exemplo.com"}'

# 3. guarde a senha devolvida, apague DASHBOARD_INSTALL_TOKEN do .env e republique
```

O endpoint grava `api/.install-lock` depois de rodar e passa a responder 404. A senha
só é devolvida quando sorteada por ele, e precisa ser trocada no primeiro acesso.

**Endurecimento aplicado.** Cookie de sessão `HttpOnly`, `SameSite=Lax`, `Secure` sob
HTTPS e restrito a `/api/`; ID de sessão regenerado logo após o login e após a troca de
senha; token CSRF por sessão emitido em `api/auth/me.php` e exigido no cabeçalho
`X-CSRF-Token` em todo endpoint que não seja GET; rate limit de login em MySQL, por
`(login, IP)` e por IP, com bloqueio progressivo e resposta `429` + `Retry-After`.
Credenciais erradas e usuário inexistente devolvem exatamente a mesma resposta.

**Segredos.** As variáveis do dashboard não usam o prefixo `NEXT_PUBLIC_`, que faria o
Next.js embutir o valor no bundle do navegador. Elas chegam ao PHP por
`public/api/env.php`, gerado pelo deploy e fora do Git — veja
[`public/api/env.example.php`](./public/api/env.example.php). Constante ausente
significa recurso desligado: nunca há senha padrão aceita.

### Publicação FTP

Copie `.env.example` para `.env`, preencha `FTP_PASSWORD` e execute:

```bash
npm run deploy:ftp
```

O comando compila o projeto e envia o conteúdo de `out/` para `FTP_UPLOAD_PATH` no
host definido em `FTP_HOST`. As credenciais de FTP e banco permanecem apenas no `.env`
local. Variáveis `NEXT_PUBLIC_*` são incluídas no JavaScript gerado e, portanto,
não devem conter segredos.


### Envio de e-mail

O endpoint `/api/contact` tenta nesta ordem:

1. **Resend** (se `RESEND_API_KEY` estiver definida) — recomendado.
2. **SMTP** (se `SMTP_HOST` + `SMTP_USER` + `SMTP_PASS` estiverem definidos) — requer `npm install nodemailer @types/nodemailer`.
3. **Modo dev** — sem nada configurado, apenas loga no terminal e retorna `200`.

Em produção sem provider configurado, retorna `500`.

### Proteções do formulário

- Validação no frontend e backend (Zod, fonte de verdade no servidor).
- Honeypot (`website` — campo invisível).
- Rate limit em memória (5 req/min por IP).
- Sanitização de header injection (CR/LF).
- Reply-To do remetente preenchido com o e-mail informado pelo usuário.

## Tokens de design

Definidos em [`app/globals.css`](./app/globals.css) via `@theme {}` do Tailwind v4. Resumo:

```css
--color-bg-dark: #0D1F0F;
--color-bg-light: #ECF5FB;
--color-accent:   #7ED957;
--color-secondary:#2D5934;
--color-text:     #242424;

--font-sans:      Montserrat
--container-max:  1140px
--radius-button:  50px (pílula)
--radius-card:    10px
```

Detalhes em [`docs/identidade-visual.md`](./docs/identidade-visual.md).

## Documentação

| Documento | Conteúdo |
| --- | --- |
| [`docs/briefing.md`](./docs/briefing.md) | Briefing original da cliente. |
| [`docs/estrutura-site.md`](./docs/estrutura-site.md) | Estrutura final das 5 páginas. |
| [`docs/identidade-visual.md`](./docs/identidade-visual.md) | Paleta + tipografia + tokens consolidados. |
| [`docs/referencia-impacta.md`](./docs/referencia-impacta.md) | Análise CSS de impactacomvoce.com.br. |
| [`docs/conversa-claude.md`](./docs/conversa-claude.md) | Conversa cliente↔Claude AI que gerou mockups. |
| [`docs/visual-references/`](./docs/visual-references/) | 6 prints aprovados pela cliente. |
| [`docs/transcricoes/`](./docs/transcricoes/) | Transcrições de áudios. |
| [`docs/PORTFOLIO ECOLETA.pdf`](./docs/PORTFOLIO%20ECOLETA.pdf) | Portfólio (logos para o carrossel). |

## Antes do go-live

- [ ] Preencher `.env.local` com dados reais (e-mail destino, WhatsApp, redes, CNPJ, endereço)
- [ ] Configurar domínio em <https://resend.com/domains> e gerar `RESEND_API_KEY`
- [ ] Substituir placeholders em `lib/site.config.ts` se quiser hardcodar
- [ ] Trocar logos placeholder em `app/cases/page.tsx` pelos reais (do `PORTFOLIO ECOLETA.pdf`)
- [ ] Substituir Gallery placeholders por fotos reais da operação (OneDrive da cliente)
- [ ] Publicar no servidor de produção via script FTP
- [ ] Configurar DNS de `ecolevaeco.com` apontando para a hospedagem
- [ ] Configurar banco de dados e criar o usuário root com `api/install.php` (ver *Dashboard*)
- [ ] Apagar `DASHBOARD_INSTALL_TOKEN` do `.env` e republicar assim que o root existir

## Comandos

```bash
npm run dev            # desenvolvimento
npm run build          # build de produção (export estático)
npm start              # servir build
npm run lint           # ESLint
npm run typecheck      # tsc --noEmit
npm test               # Vitest — front (lib/ e telas do dashboard)
npm run test:php       # PHPUnit — public/api/ (baixa o phpunit.phar em tools/)
npm run test:migrations # partes puras de db/migrate.php
```

Os testes de backend não precisam de banco nenhum: cada caso cria um SQLite
descartável e roda o endpoint em um processo PHP de verdade. O mesmo conjunto
roda em todo push e pull request pelo [`ci.yml`](.github/workflows/ci.yml).
