# AGENTS.md

> Convenções para agentes de IA (Cursor, Aider, Continue, Codex, etc.). Espelho do [`CLAUDE.md`](./CLAUDE.md) — ver lá para o conteúdo completo.

## TL;DR

- **Stack:** Next.js 16 + React 19 + TS + Tailwind v4 + Resend + Zod.
- **Comandos:** `npm run dev` · `npm run build` · `npm run lint`.
- **Tokens** estão em `app/globals.css` no bloco `@theme {}`. Usar variáveis CSS, não hex hardcoded.
- **Tipografia:** Montserrat apenas (400/500/600/700).
- **Botão pílula** (`border-radius: 50px`) é assinatura visual — não mudar.
- **Componentes próprios**, sem UI lib externa.
- **Formulário:** validação dupla (Zod), honeypot, rate limit. Tentativa Resend → SMTP → modo dev.

## Não fazer

- Não adicionar UI libs (shadcn, Material, Chakra).
- Não criar `.md` em `docs/` sem pedido — conteúdo da cliente é canônico.
- Não comitar com trailers `Co-Authored-By:` (preferência do usuário).
- Não trocar a fonte Montserrat sem realinhar.
- Não logar dados pessoais do formulário em produção.

## Estrutura mínima

```
app/        rotas (page.tsx por rota)
components/ componentes reutilizáveis
lib/        config + utilitários
docs/       documentação da cliente (read-only)
```

Para diretrizes completas, padrões de componente, lista de placeholders pendentes e contexto da cliente: leia [`CLAUDE.md`](./CLAUDE.md).
