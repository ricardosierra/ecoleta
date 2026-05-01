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
- **Server-only** — credenciais de e-mail (Resend ou SMTP), nunca expostas no client.

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
- [ ] Configurar DNS de `ecoleta.com` apontando para a hospedagem
- [ ] Migração de e-mail `@econformidade` → `@ecoleta` no Microsoft 365

## Comandos

```bash
npm run dev      # desenvolvimento
npm run build    # build de produção
npm start        # servir build
npm run lint     # ESLint
```
