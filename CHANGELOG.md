# Release Notes

---

## [Futuro]

## [Unreleased](https://github.com/ricardosierra/ecoleta/compare/v1.0.1...master)

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
