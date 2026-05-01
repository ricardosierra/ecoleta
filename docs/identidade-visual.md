# Identidade Visual — Ecoleta

> **Decisão da cliente (consolidada):** o site deve seguir o **estilo de <https://impactacomvoce.com.br/>**, mas com a **paleta da Ecoleta** (verde, não azul) e o conteúdo aprovado nos mockups da Claude AI.
>
> **Fontes deste documento:**
> 1. 6 prints dos mockups gerados no Claude AI ([`visual-references/`](./visual-references/) + [`conversa-claude.md`](./conversa-claude.md)) — definem o **conteúdo, paleta e composição visual**.
> 2. Site de referência impactacomvoce.com.br ([`referencia-impacta.md`](./referencia-impacta.md)) — define o **estilo, tipografia, componentes e ritmo de layout**.
>
> Onde houver conflito, **vale a referência do impacta** para forma e o mockup da Claude para cor.

---

## Paleta de cores

| Token              | HEX        | Uso                                            |
| ------------------ | ---------- | ---------------------------------------------- |
| **Verde escuro**   | `#0D1F0F`  | Fundo principal de seções escuras (hero, solução, diferencial operacional, CTA) |
| **Verde vibrante** | `#7ED957`  | Cor de destaque — palavras-chave, ícones, gráficos, CTA final, badges |
| **Verde médio**    | `#2d5934`  | Cor secundária do briefing (botão diagnóstico no nav, eyebrow labels) |
| **Off-white**      | `#ECF5FB` (ou similar) | Fundo de seções claras (problema, diferenciais, resultados, "três pilares") |
| **Cinza neutro**   | tons de cinza | Texto secundário, divisores, cards desabilitados |
| **Acentos pontuais** | laranja, vermelho/rosa, amarelo | Tags por tipo de resíduo (recicláveis verde, orgânicos verde claro, rejeitos cinza, infectantes vermelho, têxteis amarelo) |

> **Conflito a resolver com a cliente:** o briefing original define `#2d5934` como verde principal; os mockups da Claude AI usam `#0D1F0F` (mais escuro, quase preto) + `#7ED957` (mais saturado). Recomendação: manter o esquema dos mockups (já validado visualmente pela cliente) e usar `#2d5934` como tom intermediário/secundário.

## Tipografia

> **Decisão final:** usar **Montserrat** (alinhada ao site de referência impactacomvoce.com.br). Substitui Syne/DM Sans propostos no mockup inicial.

- **Família única:** `Montserrat` — pesos 400 (corpo), 500 (UI), 600 (semibold/destaques), 700 (títulos).
- **Hierarquia recomendada (alinhada ao impacta):**

  | Uso        | Desktop | Mobile | Peso       |
  | ---------- | ------- | ------ | ---------- |
  | Hero H1    | 68px    | 42px   | 700        |
  | H2 seção   | 42px    | 32px   | 700        |
  | H3 card    | 22–26px | 20px   | 600        |
  | Subtítulo  | 18px    | 16px   | 400        |
  | Corpo      | 16px    | 16px   | 400        |
  | Small      | 14px    | 14px   | 500        |
  | Eyebrow    | 13–14px | 13px   | 500 UPPERCASE, tracking ampliado |

- **Eyebrows** (rótulos pequenos acima dos títulos das seções) em letras maiúsculas com tracking aumentado — exatamente como visto nos mockups (`O PROBLEMA`, `A SOLUÇÃO`, `IMPACTO DE CARBONO`, `RESULTADOS`, `DIFERENCIAL OPERACIONAL`, etc.).

## Logotipo

- Wordmark `ecoleta` em letras minúsculas, peso bold/semibold.
- Aparece em verde vibrante sobre fundo escuro e em verde escuro sobre fundo claro (Header).

## Estrutura de navegação

```
[ecoleta]   Soluções · ESG · Cases · Contato        [ Diagnóstico gratuito ]
```

- Nav fixo no topo.
- Item ativo destacado com pílula verde (visto na aba "Soluções").
- CTA principal no canto direito (botão off-white sobre fundo escuro).

## Componentes visuais identificados

### Hero (escuro)

- Badge superior com bullet verde + texto: `● Conformidade PNRS · ISO 14001`.
- Título grande com **palavra-chave em verde vibrante** (ex: "rastreabilidade").
- Subtítulo em cinza claro (~2 linhas).
- Dois CTAs lado a lado: primário (claro) e secundário (outline).

### Cards de problema (claro)

- 5 cards horizontais com ícone de alerta (triângulo) em laranja.
- Título curto + descrição curta.
- Borda sutil, fundo branco/off-white.

### Lista de solução (escuro)

- Cards arredondados em verde escuro mais profundo.
- Ícone circular verde com check à esquerda + texto à direita.
- Stack vertical com bom espaçamento.

### Cards de diferenciais (claro)

- Grid 3 colunas, cards brancos.
- Seta `→` no topo + título em bold + descrição.

### Bloco de carbono (escuro)

- Texto à esquerda, **gráfico circular (donut) verde com `68%` ao centro** à direita.
- Label "desvio de aterro" abaixo do número.

### Métricas / resultados (claro)

- Linha de 5 cards brancos com bordas sutis.
- Cada card: ícone/símbolo grande no topo (↓, 68%, ✓, ⚖, "ESG"), texto descritivo curto abaixo.

### CTA final (verde claro)

- Bloco verde vibrante full-width.
- Título grande em verde escuro.
- Botão em off-white com texto escuro.

### Página Soluções — fluxo "Como funciona"

- 6 etapas numeradas em **círculos verdes** ligados por linha horizontal contínua.
- Cada etapa: número + título + descrição curta abaixo.
- Etapas: Diagnóstico → Planejamento → Implementação → Operação → Destinação → Relatório ESG.

### Página Soluções — "Três pilares"

- 3 cards brancos com **ícone verde no topo** (gear, broto, documento), título bold, parágrafo + lista de bullets em verde.

### Resíduos atendidos

- **Pílulas arredondadas escuras** com bullet colorido + label + tag interna.
- Bullets por tipo:
  - Recicláveis → verde + tag "Papel · Plástico · Metal · Vidro"
  - Orgânicos → verde claro + tag "Compostagem"
  - Rejeitos → cinza + tag "Destinação adequada"
  - Infectantes → rosa/vermelho + tag "Licença específica"
  - Têxteis → amarelo + tag "Doação · Reuso"

### Diferencial operacional (escuro)

- Bloco escuro full-width.
- Coluna esquerda: título + descrição + 3 botões pílula com seta (`→ Menos mistura`, `→ Menos desperdício`, `→ Mais controle`).
- Coluna direita: 3 stat cards verde escuro empilhados (`68% desvio de aterro`, `100% rastreabilidade`, `PNRS conformidade total`).

### CTAs intermediários

Botão escuro arredondado sobre fundo verde — texto: "Inicie sua própria conversa". Aparece como CTA recorrente entre seções.

## Princípios de design

1. **Contraste alto:** alternância entre fundo escuro e claro entre seções.
2. **Verde como sinalização:** o verde vibrante só aparece em pontos de ênfase (palavras-chave, ícones, CTAs, dados numéricos).
3. **Espaço generoso:** bastante respiro entre blocos, conforme diretriz do briefing.
4. **Informação em camadas:** eyebrow → título grande → subtítulo → conteúdo, repetindo em todas as seções.
5. **Cards com personalidade:** bordas arredondadas grandes (8–16px), sombras sutis ou nenhuma.
6. **Iconografia:** estilo line/outline, peso fino, cor verde sobre claro ou claro sobre verde escuro.

## Tokens consolidados (impacta + Ecoleta)

```css
:root {
  /* Container */
  --container-max: 1140px;
  --section-py: 80px;
  --section-py-mobile: 48px;

  /* Tipografia */
  --font-primary: "Montserrat", system-ui, sans-serif;
  --fs-hero: 68px;
  --fs-hero-mobile: 42px;
  --fs-h2: 42px;
  --fs-h3: 26px;
  --fs-body: 16px;
  --fs-small: 14px;

  /* Componentes — radius do impacta */
  --radius-button: 50px;       /* pílula total — assinatura do estilo */
  --radius-card: 10px;
  --radius-input: 5px;
  --btn-padding: 15px 35px;
  --card-padding: 20px;

  /* Sombras — do impacta */
  --shadow-sm: 0 0 10px rgba(0,0,0,0.18);
  --shadow-md: 0 0 10px rgba(0,0,0,0.22);
  --shadow-lg: 0 10px 12px -5px rgba(0,0,0,0.3);

  /* Cores — Ecoleta (substituem azul do impacta pelo verde escuro) */
  --color-bg-dark: #0D1F0F;
  --color-bg-light: #ECF5FB;
  --color-accent: #7ED957;
  --color-secondary: #2D5934;
  --color-text: #242424;
  --color-text-muted: #363636;
  --color-white: #FFFFFF;
}
```

> Detalhes completos dos tokens e justificativa em [`referencia-impacta.md`](./referencia-impacta.md).

## Referências visuais

- [`01-home-hero-problema.png`](./visual-references/01-home-hero-problema.png) — Nav, hero escuro com badge, problema com cards de alerta.
- [`02-home-solucao-diferenciais.png`](./visual-references/02-home-solucao-diferenciais.png) — Solução em fundo escuro, início dos diferenciais.
- [`03-home-carbono-resultados-cta.png`](./visual-references/03-home-carbono-resultados-cta.png) — Bloco de carbono com gráfico 68%, métricas, CTA verde final.
- [`04-solucoes-hero-como-funciona.png`](./visual-references/04-solucoes-hero-como-funciona.png) — Hero da página Soluções, fluxo de 6 etapas.
- [`05-solucoes-pilares-residuos.png`](./visual-references/05-solucoes-pilares-residuos.png) — Três pilares + pílulas de resíduos.
- [`06-solucoes-diferencial-cta.png`](./visual-references/06-solucoes-diferencial-cta.png) — Diferencial operacional com 3 stat cards + CTA final.

## Site de referência (briefing)

A cliente indicou <https://impactacomvoce.com.br/> como inspiração estrutural ("moderno, maneiro").
