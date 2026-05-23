# Release Notes

---

## [Futuro]

## [Unreleased](https://github.com/ricardosierra/ecoleta/compare/v1.0.3...master)

## [v1.0.3 (2026-05-23)](https://github.com/ricardosierra/ecoleta/compare/v1.0.2...v1.0.3)

### 🎨 Melhorias

- [x] Rebranding textual e visual de Ecoleta para Ecoleva em páginas, metadados, feed, manifesto, formulário e e-mails de contato
- [x] Novas marcas Ecoleva aplicadas nas variações clara e escura, com ajustes em favicon, ícones PWA e imagem OG
- [x] Carrossel de clientes revisado com logos corrigidos e inclusão de GH Music e Subsea 7
- [x] Cards de impacto ESG redesenhados com fundo escuro, ícones próprios e maior hierarquia visual
- [x] Página Sobre atualizada com novas imagens de equipe, triagem e pontos de coleta sustentável
- [x] Página Soluções recebeu ícones próprios para as frações de resíduos atendidas
- [x] Rodapé atualizado com assinatura de desenvolvimento RicaSolucoes e SierraTecnologia

### 🐛 Correções

- [x] Logo branca corrigida para usar asset branco real em fundos escuros
- [x] Menu mobile fecha ao navegar, fecha com Escape e bloqueia interação quando está oculto
- [x] Animação `LetterReveal` passou a revelar o texto completo para evitar palavras parciais em títulos
- [x] Ajustes de tamanho, espaçamento e alinhamento em cards, botões e listas para melhorar leitura responsiva

### 🔧 Técnico

- [x] Novos ícones SVG internos adicionados sem dependência de UI externa
- [x] Configurações centrais atualizadas em `siteConfig`, manifesto, feed XML e `contact.php`
- [x] Build estático regenerado e empacotado em `ecoleta-out.zip`
- [x] Validação executada com `npm run build` e `npm run lint`

## [v1.0.1 (2026-05-13)](https://github.com/ricardosierra/ecoleta/compare/v1.0.0...v1.0.1)

### 🎨 Melhorias

- [x] Nova identidade visual aplicada a logo, favicon, ícones PWA e imagem OG a partir da arte atualizada da cliente
- [x] Escala visual geral reduzida em títulos, cards, botões e espaçamentos para melhorar leitura em desktop e mobile
- [x] Vídeo inicial mais visível e com reprodução desacelerada no hero da Home
- [x] Home atualizada com novos textos de diferenciais, CTA final e indicadores de desvio de aterro em 92%
- [x] Página Soluções atualizada com pilares de gestão operacional, ESG aplicado, conformidade e frações de resíduos revisadas
- [x] Página ESG reorganizada com cards escuros, textos novos de Ambiental/Social/Governança e comprovação ambiental completa
- [x] Página Sobre recebeu o case modelo, galeria sem legendas, foto da equipe, dashboard e imagens de treinamento
- [x] Rodapé atualizado com texto final solicitado e sem telefone comercial duplicado

### 🐛 Correções

- [x] Animações de palavras e letras ficam estáticas no mobile para evitar travamentos
- [x] Percentuais antigos de 68%, 93%, 94% e 96% foram padronizados conforme solicitação da cliente
- [x] Galeria não exibe mais legenda sobre as fotos e remove foto repetida

### 🔧 Técnico

- [x] Página `Cases` removida da navegação, sitemap e build estático
- [x] Conteúdo remanescente de `Cases` redistribuído entre `Sobre` e `ESG`
- [x] Componente `Gallery` simplificado para aceitar apenas `src` e `alt`
- [x] Componente `Logo` passa a usar assets processados por variante visual
- [x] Componente `HeroVideo` centraliza controle de velocidade do vídeo inicial
