# Referência Visual — impactacomvoce.com.br

> **Pedido da cliente:** *"A cliente quer tudo nesse mesmo estilo aqui: <https://impactacomvoce.com.br/>"*
>
> Este documento extrai o **estilo** do site de referência (tokens, ritmo, componentes) para o time replicar com a paleta da Ecoleta. Conteúdo e cores ficam na Ecoleta — **layout, tipografia, espaçamentos e componentes** seguem o impacta.

---

## Stack do site original (apenas para análise)

- WordPress + tema **Hello Elementor** + **Elementor Pro**
- Container Elementor padrão: `max-width: 1140px`
- Plugins detectados: Premium Addons, WPRocket (cache), Complianz (LGPD)

> **Não precisamos usar WordPress para a Ecoleta.** O TI escolhe a stack — basta replicar a aparência.

---

## 1. Paleta extraída do site (tokens reais)

Cores reais usadas no CSS gerado pelo Elementor (contagem = nº de ocorrências):

| HEX        | Papel                                | Ocorrências |
| ---------- | ------------------------------------ | ----------- |
| `#FFFFFF`  | Branco — fundo dominante             | 52          |
| `#0055A0`  | **Azul corporativo (primário)**      | 39          |
| `#363636`  | Cinza escuro — texto/seções          | 30          |
| `#242424`  | Quase preto — texto título           | 26          |
| `#00724D`  | Verde escuro institucional           | 16          |
| `#009869`  | Verde médio                          | 14          |
| `#61CE70`  | Verde claro (accent secundário)      | 6           |
| `#CB574F`  | Coral/terracota (accent pontual)     | 5           |
| `#E4AE5B`  | Mostarda (accent pontual)            | 4           |
| `#FF766A`  | Coral claro                          | 3           |

**Leitura:** o site é dominado por **branco + azul `#0055A0`**, com verdes (`#00724D`, `#009869`, `#61CE70`) como tema temático e accents quentes (coral, mostarda) em pontos específicos.

> **Importante:** vamos manter a **paleta da Ecoleta** (`#0D1F0F` + `#7ED957` + `#ECF5FB`) mas substituir o azul `#0055A0` pelo nosso verde escuro como cor primária equivalente. Ver [`identidade-visual.md`](./identidade-visual.md) para reconciliação.

## 2. Tipografia extraída

| Família       | Ocorrências | Uso                |
| ------------- | ----------- | ------------------ |
| **Montserrat** | **54**     | Principal (títulos + corpo) |
| Inter         | 12          | Secundária         |
| Lato          | 6           | Pontual            |
| Roboto        | 7+3         | Default Elementor  |
| Poppins       | 3           | Pontual            |

**→ Para a Ecoleta: adotar Montserrat como tipografia principal** (substitui Syne/DM Sans propostos nos mockups iniciais — Montserrat é mais alinhada à referência aprovada pela cliente).

### Pesos usados

- **400** (regular) — corpo (37 ocorrências)
- **700** (bold) — títulos (15)
- **600** (semibold) — destaques (8)
- **500** (medium) — UI (9)
- 800 e 300 — pontual

### Tamanhos observados

| Tamanho | Uso provável            | Frequência |
| ------- | ----------------------- | ---------- |
| 16px    | Corpo padrão            | 44         |
| 14px    | Texto pequeno / labels  | 28         |
| 18px    | Subtítulos              | 18         |
| 42px    | Títulos de seção / H1   | 14         |
| 68px    | Hero gigante            | 1          |
| 28/26/24/22/20px | Hierarquias intermediárias | 14 |

**Ritmo tipográfico recomendado:**

```
Hero:        68px / 42px (mobile)  — bold 700, tracking apertado
H2 seção:    42px / 32px (mobile)  — bold 700
H3 card:     22-26px               — semibold 600
Subtítulo:   18px                  — regular 400
Corpo:       16px                  — regular 400, line-height ~1.6
Small/label: 14px                  — medium 500
Eyebrow:     13-14px UPPERCASE     — letter-spacing aumentado
```

## 3. Layout & Container

- **Container max-width: `1140px`** (com fallback 1024px em tablet, 767px em mobile).
- Padding lateral generoso em mobile.
- Seções com **padding vertical de 60–100px** desktop, 40–60px mobile.
- Gap entre widgets: 20px (default).
- Block gap: 24px.

## 4. Componentes — tokens extraídos

### Botões

- **Border-radius: `50px`** (pílula total) — 12 ocorrências, **dominante**.
- Padding: `15px 35px` (10×) ou `12px 32px` (4×).
- Filled (cor sólida) com texto contrastante.
- Hover provável: shift de cor / opacidade.

```css
.btn {
  padding: 15px 35px;
  border-radius: 50px;
  font-family: "Montserrat", sans-serif;
  font-weight: 600;
  font-size: 16px;
}
```

### Cards

- Border-radius: **`10px`** (7×) ou `5px` (10×, mais para inputs).
- Padding interno: `20px` ou `30px`.
- Variante criativa: `border-radius: 30px 0px 30px 0px` (cantos opostos arredondados — assinatura visual).

### Sombras (elevação)

```css
/* Card sutil */
box-shadow: 0px 0px 10px 0px rgba(0, 0, 0, 0.18);

/* Card destacado */
box-shadow: 0px 0px 10px 0px rgba(0, 0, 0, 0.22);

/* Elevação maior (CTA flutuante / hover) */
box-shadow: 0px 10px 12px -5px rgba(0, 0, 0, 0.3);

/* Sombra superior (decorativa) */
box-shadow: 0px -21px 29px 0px rgba(0, 0, 0, 0.13);
```

### Formulários

- Border-radius: 5px nos inputs.
- Visual limpo, label acima do campo.

## 5. Estrutura de página observada

Olhando o conteúdo da home do impacta:

1. **Header fixo** com logo à esquerda + menu horizontal + CTA à direita.
2. **Hero** com texto centralizado/à esquerda, headline aspiracional, subtítulo, CTA pílula.
3. Seção **"O que fazemos"** — H1 grande introduzindo cards de serviço (Gestão de Resíduos, Gestão da Sustentabilidade do Negócio, Sets de Filmagem e Eventos, Engajamento, Consultorias Avançadas).
4. **"Nossas coletas são carbono neutro"** — bloco de impacto com destaque numérico.
5. **"Sobre a Impacta"** — institucional.
6. **"Propósito"** + **"Valores"** — listas com ícones (Responsabilidade social, Uso de soluções baseadas nos ciclos naturais, Cocriação, Inovação para o máximo aproveitamento de recursos e materiais).
7. **Carrossel de clientes** (logos monocromáticas).
8. **Formulário de contato** + footer.

> **Boa notícia:** a estrutura da Ecoleta (5 abas — Home, Soluções, ESG, Cases, Sobre/Contato) já cobre todos esses blocos. Ver [`estrutura-site.md`](./estrutura-site.md).

## 6. Imagens, ícones e ilustrações

- **Fotografia:** corporativa-ambiental (pessoas trabalhando, natureza, operação real). A Ecoleta tem material no OneDrive — usar.
- **Ícones:** estilo line/outline simples, geométrico, coloridos no accent verde.
- **Ilustrações:** motivos de folha/natureza como divisores entre seções.
- **Logos de clientes:** versões monocromáticas em carrossel — a Ecoleta tem o `PORTFOLIO ECOLETA.pdf` com a lista, conforme [comentário da cliente no áudio](./transcricoes/audio-2026-04-30-cliente-comentario-portfolio.md).

## 7. Animações e micro-interações

- Reveal on scroll (fade/slide) nos blocos.
- Hover em botões e cards: leve shift de cor / opacidade / elevação.
- Carrossel de logos auto-rotativo.
- Smooth scroll em âncoras do menu.
- Transições rápidas (~200–300ms).

## 8. Mobile

- Menu colapsa em hamburguer.
- Grid 1 coluna em mobile, 2–3 em desktop.
- Tipografia escala proporcional (hero 42px em mobile, 68px desktop).

## 9. Vibe geral

**Profissional + acessível + ESG**, sem rigidez corporativa. Botões pílula totais (border-radius 50px) trazem leveza; sombras suaves dão profundidade; abundância de branco gera respiração; verde aparece como sinalização do propósito ambiental.

A Ecoleta deve transmitir a mesma sensação, **trocando o azul corporativo do impacta pelo verde escuro institucional `#0D1F0F`** (que é a cor que diferencia a Ecoleta — ver [`identidade-visual.md`](./identidade-visual.md)).

---

## Resumo de tokens para o TI

```css
/* Container */
--container-max: 1140px;
--section-py: 80px;          /* desktop */
--section-py-mobile: 48px;

/* Tipografia */
--font-primary: "Montserrat", system-ui, sans-serif;
--fs-hero: 68px;             /* desktop */
--fs-hero-mobile: 42px;
--fs-h2: 42px;
--fs-h3: 26px;
--fs-body: 16px;
--fs-small: 14px;
--fw-regular: 400;
--fw-medium: 500;
--fw-semibold: 600;
--fw-bold: 700;

/* Componentes */
--radius-button: 50px;       /* pílula total */
--radius-card: 10px;
--radius-input: 5px;
--btn-padding: 15px 35px;
--card-padding: 20px;

/* Sombras */
--shadow-sm: 0 0 10px rgba(0,0,0,0.18);
--shadow-md: 0 0 10px rgba(0,0,0,0.22);
--shadow-lg: 0 10px 12px -5px rgba(0,0,0,0.3);

/* Cores Ecoleta (substituem o azul do impacta) */
--color-bg-dark: #0D1F0F;    /* verde escuro institucional */
--color-bg-light: #ECF5FB;   /* off-white seções alternadas */
--color-accent: #7ED957;     /* verde vibrante (destaques) */
--color-secondary: #2D5934;  /* verde médio (CTAs secundários) */
--color-text: #242424;       /* texto principal */
--color-text-muted: #363636; /* texto secundário */
--color-white: #FFFFFF;
```
