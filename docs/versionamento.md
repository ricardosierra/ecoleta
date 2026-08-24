# Versionamento — por que a série continua em v1.x

> Documento técnico do repositório (não é material da cliente).

A regra do portfólio é que todo projeto começa em `v0.1.0` e que `v1.0.0` fica
reservado para produção com muitos usuários. O Ecoleta não seguiu isso: saiu de
`v0.1.1` direto para `v1.0.0` e hoje está em `v1.2.0`. Este documento registra
o estado medido, a decisão tomada e o que custaria desfazê-la.

## O que existe hoje

Nove referências, **todas já empurradas para `origin`**
(`github.com/ricardosierra/ecoleta`, repositório público):

| Tag | Tipo | Commit | Observação |
| --- | --- | --- | --- |
| `v0.1.0` | leve | `1fc78b5` | |
| `v0.1.1` | leve | `a43a7a2` | |
| `v1.0.0` | leve | `ffa8610` | o salto |
| `v1.0.1` | anotada | `5d193b4` | |
| `v1.0.2` | anotada | `5d193b4` | **mesmo commit da `v1.0.1`** |
| `v1.0.3` | anotada | `fd76e75` | |
| `v1.0.4` | anotada | `1e6f186` | |
| `v1.2.0` | anotada | `7510461` | |
| `version-js` | leve | `1fc78b5` | **mesmo commit da `v0.1.0`** |

Fora da lista: **`v1.1.0` nunca existiu**. O CHANGELOG trazia uma seção para
ela, mas nem local nem `origin` têm a tag, e não há commit em que ela caiba —
os endpoints que a seção descreve (`users/generate_password.php`,
`users/delete.php`, a trilha `activity_logs`) aparecem pela primeira vez em
`7510461`, que é justamente o commit da `v1.2.0`. As duas seções eram um commit
só. Foram unidas sob a `v1.2.0`, sem perder nenhum item.

Não há Releases no GitHub — as tags são só tags, sem página nem binário
anexado. O repositório tem 0 forks e 0 stars, e nada aqui é publicado em
registry: o deploy é export estático por FTP. Ou seja, **ninguém consome esses
números** além do próprio histórico.

## A tag `version-js`

Lixo. É uma tag leve apontando para `1fc78b5`, exatamente o commit que a
`v0.1.0` já marca — não guarda nada que a `v0.1.0` não guarde. Não segue padrão
nenhum e provavelmente sobrou de um marcador de branch. Some sem perda:

```
git tag -d version-js
git push origin :refs/tags/version-js
```

A `v1.0.2` está na mesma situação em relação à `v1.0.1` (mesmo commit), mas ela
tem link de compare no CHANGELOG (`v1.0.2...v1.0.3`) e mensagem de release
própria: apagar quebraria o arquivo. Fica.

## As duas opções

**(a) Manter a série `v1.x` como fato consumado.** Nada é reescrito, os links
de compare continuam de pé, o histórico segue monotônico. A regra do portfólio
fica violada neste repositório, e a próxima release é `v1.3.0`.

**(b) Renumerar para `v0.x`.** Respeita a regra, mas exige apagar e recriar seis
tags que já estão públicas em `origin`, reescrever os cinco links de compare do
CHANGELOG, e voltar `package.json` de `1.2.0` para algo como `0.5.0`. Quem já
clonou fica com as tags antigas até rodar `git fetch --prune-tags`, e
`git describe` passa a responder outra coisa para os mesmos commits.

## A decisão: (a)

Três motivos, em ordem de peso.

**O custo de (b) é real e o benefício é cosmético.** As tags já estão em um
remoto público. Renumerar não é renomear um arquivo local: é apagar referências
publicadas e republicar outras no lugar. Isso se paga quando alguém depende dos
números — e aqui ninguém depende, o que corta o argumento nos dois sentidos: se
ninguém consome, ninguém está sendo enganado pelo `1` também.

**Versão não anda para trás.** Depois de `v1.2.0` público, publicar `v0.5.0`
como versão seguinte quebra a única garantia que um número de versão dá: que
ele cresce. Um `v0.x` depois de um `v1.x` confunde mais do que o `v1.0.0`
precoce confundia.

**O espírito da regra está quase satisfeito.** `v1.0.0` é marco de maturidade,
e o Ecoleta está de fato em produção para a cliente, com dashboard, contas,
grupos e Power BI. "Muitos usuários" não, mas prematuro-e-defensável é bem
diferente de absurdo.

A regra continua valendo para projeto novo. O que ela não consegue é ser
aplicada retroativamente sobre tag pública sem cobrar mais do que entrega.

## Daqui para frente

- Próxima release: **`v1.3.0`** (o `[Unreleased]` de hoje é feature nova).
- `package.json` fica em `1.2.0` até a release ser fechada.
- Todo heading de versão do CHANGELOG precisa corresponder a uma tag que exista
  em `origin` — foi o que quebrou com a `v1.1.0`. Escrever a seção **e** criar a
  tag, no mesmo passo.

## Se um dia (b) for a escolha

O caminho, na ordem, com backup antes de qualquer coisa:

```
git bundle create ../ecoleta-backup-tags.bundle --all
git tag v0.2.0 v1.0.0^{}   # e assim por diante para as demais
git tag -d v1.0.0 ...      # apaga as antigas
git push origin --delete v1.0.0 ...
git push origin --tags
git fetch --prune-tags     # em cada clone existente
```

E, no mesmo commit, reescrever os cinco links de compare do CHANGELOG e a
versão do `package.json`. Não vale fazer metade: tag renumerada com link antigo
dá 404, que é pior do que o número errado.
