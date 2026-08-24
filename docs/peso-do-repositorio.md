# Peso do repositório — o que saiu e o que falta decidir

> Documento técnico do repositório (não é material da cliente).

O `.git` do Ecoleta pesa **186 MB**. Um clone novo baixa isso inteiro, hoje e
sempre, mesmo depois de os arquivos saírem do diretório de trabalho — apagar um
binário não tira o peso dele do histórico. Este documento registra o que foi
medido, o que já foi feito, e as duas decisões que continuam abertas.

## O que existe hoje

Todo o conteúdo versionado soma **183,98 MB em 469 blobs**. A distribuição não é
equilibrada:

| Peso no histórico | Blobs | Arquivo |
| --: | --: | :-- |
| **143,10 MB** | **3** | `ecoleta-out.zip` |
| 14,16 MB | 1 | `public/hero-video.webm` |
| 12,77 MB | 1 | `public/hero-video.mp4` |
| 3,29 MB | 1 | `docs/PORTFOLIO ECOLETA.pdf` |
| 5,86 MB | 3 | as três maiores imagens de `public/gallery/` |
| ~4,80 MB | 460 | **todo o resto** — código, testes, documentação, o histórico inteiro |

A primeira linha corrige o número que motivou esta tarefa. O zip **não** pesa
72 MB: ele foi commitado três vezes, com conteúdos diferentes, e o histórico
guarda as três versões — 72,64 MB + 36,32 MB + 34,14 MB. Sozinho, ele é **78%
de tudo que o repositório carrega**. Todo o código do projeto, somado, é 2,6%.

Ele entrou em `8a4ce51` ("Atualiza site Ecoleta", 2026-05-23) e está na árvore
de **54 dos 85 commits**, em todas as branches e em `origin/master`.

Não há outros binários grandes escondidos: a varredura cobriu o histórico
inteiro, não só o diretório de trabalho. Os únicos 15 caminhos que existem no
histórico mas não em `HEAD` são arquivos-fonte pequenos, removidos em
refatorações.

## O que já foi feito

`ecoleta-out.zip` saiu do rastreamento (`git rm --cached`) e o `.gitignore`
passou a cobrir `*.zip` e os outros formatos de pacote.

Antes de tirar, a pergunta certa era se o zip guardava alguma coisa que só
existia ali. Não guarda. As 501 entradas foram conferidas contra uma build
limpa: **zero órfãs**. As 14 entradas PHP são cópias de `public/api/`, todas
rastreadas. A única diferença entre o zip e uma build nova são nomes de chunk
com hash — e o zip carrega **dois build IDs diferentes**, sinal de que o pacote
foi se acumulando entre builds em vez de ser regerado do zero.

Ele também estava velho: não tem os cinco logos de `public/logos/`, nem
`api/authz.php`, `install.php`, `rate_limit.php`, `schema.php` e `security.php`.
Publicar a partir dele hoje derrubaria o dashboard.

**Isso não encolheu o `.git` em um byte.** `git rm --cached` só interrompe o
crescimento: os três blobs continuam no histórico e em todo clone. Quem quiser
o espaço de volta precisa da reescrita descrita mais abaixo.

Uma observação que apareceu no caminho: o zip só não voltava para o índice por
causa da regra `*.zip` do `~/.gitignore_global`, que existe **apenas na máquina
do dono**. Em outra máquina, ou num runner de CI, um `git add .` recolocaria os
76 MB. `ecoleta-site-2026-05-29.zip`, 36 MB soltos na raiz, dependia da mesma
sorte. A regra agora mora no repositório.

## Decisão aberta 1 — os vídeos do hero

`public/hero-video.mp4` e `public/hero-video.webm` somam **26,93 MB**. São
asset de produto: `components/HeroVideo.tsx` os usa como plano de fundo do hero
da home, mudo, em loop, a 0,65x da velocidade. Os dois formatos se justificam —
o `browserslist` do projeto inclui Safari 14, que não toca WebM.

**A recomendação é manter no git, e a razão é que eles não crescem.** Cada um
tem **um único blob** no histórico: entraram em `9788335` e nunca mudaram. O
custo deles é 27 MB pagos uma vez, e é isso para sempre. O zip era o oposto —
três blobs em três commits, crescendo a cada publicação. É essa diferença, e
não o tamanho, que separa um caso do outro.

Contra tirar do git pesa o deploy. `scripts/deploy-ftp.sh` sobe **arquivo por
arquivo, com um `curl` por arquivo**, tudo que está em `out/`. Enquanto os
vídeos vivem em `public/`, o `next build` os copia para `out/` e eles sobem
junto: o build é autossuficiente. Tirando-os do git, o site publicado passa a
depender de alguém lembrar de subir os vídeos por fora — um segundo caminho de
falha num deploy que já exige ordenar migrations à mão.

Git LFS não ajuda aqui. O repositório é público no GitHub, onde LFS tem cota de
banda; o script de FTP precisa dos bytes reais no disco, então o checkout
passaria a depender do smudge filter; e o peso continuaria sendo baixado, só
que de outro servidor.

**O ganho real não é mudar de lugar, é reencodar.** Os dois arquivos são
720x1280, **30,5 segundos, a 3,5–3,9 Mbps** — muito acima do necessário para um
fundo decorativo mudo. Um loop de 8 a 12 segundos a ~1,2 Mbps entregaria o mesmo
efeito visual em torno de 3 a 5 MB, cortando ~85% do peso e melhorando o tempo
de carregamento da home para quem entra pelo celular.

Com uma ressalva de ordem que importa: **reencodar hoje deixa o repositório
maior.** Os blobs novos entram, os antigos continuam no histórico. O reencode
só rende junto com a reescrita abaixo, e por isso a sequência correta é decidir
a reescrita primeiro.

## Decisão aberta 2 — reescrever o histórico

**Nada disto foi executado.** O que segue foi medido em clones descartáveis
(`git clone --no-hardlinks --mirror`), fora do repositório, apagados em seguida.

### Quanto rende

| Cenário | `.git` |
| :-- | --: |
| hoje | 185 MB |
| removendo `ecoleta-out.zip` | **41 MB** (−78%) |
| removendo também os dois vídeos | 14 MB (−92%) |
| removendo também o PDF do portfólio | 10 MB (−95%) |

### Duas armadilhas que a execução ingênua encontra

**1. O comando óbvio deixa 36 MB para trás.** O repositório tem quatro refs
`refs/codex/turn-diffs/checkpoints/…` que apontam direto para objetos *tree*.
O `git-filter-repo` não sabe processá-las, avisa `Unexpected object of type
tree, skipping` e segue — e uma delas segura o blob de 36 MB do zip. Resultado
medido: 185 MB → **77 MB**, não 41 MB. As refs precisam ser apagadas antes.

**2. O `git-filter-repo` instalado quebra antes de começar.** A versão 2.47.0
lê `git config --list` com um parser ingênuo, e o `~/.gitconfig` do dono tem
aliases (`alias.tests`, `alias.teste`, `alias.new`, `alias.update`) com quebras
de linha literais no valor. O resultado é
`ValueError: dictionary update sequence element #20 has length 1`. Contorna-se
rodando com o config global desligado.

### A sequência que funciona

```bash
cd ~/Dev/RicaSolucoes
git bundle create ecoleta-backup-$(date +%F).bundle --all   # backup primeiro
git clone --no-hardlinks --mirror Ecoleta ecoleta-rewrite
cd ecoleta-rewrite

git for-each-ref --format='%(refname)' 'refs/codex/**' \
  | while read -r r; do git update-ref -d "$r"; done

PATH="$HOME/Library/Python/3.9/bin:$PATH" \
GIT_CONFIG_GLOBAL=/dev/null git-filter-repo --invert-paths \
  --path ecoleta-out.zip

git reflog expire --expire=now --all && git gc --prune=now
```

Verificado no teste: as **8 branches e as 9 tags sobrevivem**, com hashes novos.
As quatro branches `claude/*` de tarefas anteriores, que ainda não foram
empurradas, continuam lá. `v0.1.0`, `v0.1.1` e `v1.0.0` mantêm o hash original,
porque são anteriores ao commit que trouxe o zip.

### O que custa

Todo commit a partir de `8a4ce51` ganha hash novo. Na prática:

- **As 9 tags já estão em `origin`** (`v0.1.0` … `v1.2.0`, `version-js`).
  Todas apontariam para commits que deixam de existir no remoto, e teriam que
  ser reempurradas com `--force`.
- **`origin/master` diverge de forma irreconciliável.** A publicação exige
  `git push --force --all` e `--force --tags`.
- Qualquer clone existente para de conseguir `git pull` e precisa ser refeito.

Sobre quem seria afetado: `github.com/ricardosierra/ecoleta` é **público**, mas
tem **0 forks, 0 stars e um único colaborador — o próprio dono**. Não há
pull request aberto. O risco de quebrar o trabalho de terceiros é, dentro do
que dá para verificar, nulo. Clones anônimos de um repositório público não são
rastreáveis, mas com 0 stars e 0 forks a probabilidade é desprezível.

### Por que a reescrita não foi executada

Não é falta de autorização: é que **uma reescrita apenas local seria pior do que
não fazer nada.**

O peso mora em `origin`. Reescrever o histórico local sem publicar deixaria o
clone do dono permanentemente divergente do remoto — sem `git pull`, sem `git
push` que não seja `--force` — enquanto os 143 MB continuariam no GitHub e em
todo clone novo. Ou seja: pagaria o custo inteiro da operação e não resolveria
o problema que a motivou.

A operação só faz sentido inteira: reescrever **e** publicar com `--force`, numa
janela escolhida pelo dono, com o bundle de backup guardado. Como o `--force
push` está fora do escopo desta tarefa, a reescrita fica proposta e medida,
pronta para ser executada quando o dono decidir.

## Achado lateral — credencial a um `git add` de distância

Vale registrar porque quase aconteceu.

`out/api/env.php` é gerado pelo `deploy-ftp.sh` e contém as credenciais de
produção do MySQL em texto puro. Ele é ignorado por causa da regra `/out/` —
mas **um zip de `out/` não era ignorado por nenhuma regra do repositório**, e um
zip de `out/` foi exatamente o que entrou no histórico três vezes.

Os dois zips foram conferidos: **nenhum dos dois contém `env.php`**. As três
versões commitadas do `ecoleta-out.zip` foram empacotadas antes de o arquivo
existir naquele diretório. Foi sorte, não desenho — e é a razão de o `*.zip`
agora estar no `.gitignore` do repositório, e não só no global do dono.
