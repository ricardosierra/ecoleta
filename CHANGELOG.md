# Release Notes

---

## [Futuro]

## [Unreleased](https://github.com/ricardosierra/ecoleta/compare/v1.2.0...master)

## [v1.2.0 (2026-08-21)](https://github.com/ricardosierra/ecoleta/compare/v1.1.0...v1.2.0)

### 🚀 Funcionalidades

- [x] **Gestão de Grupos (`/dashboard/grupos/`)**: Tela exclusiva para `root` e `master` com cadastro, edição (nome e código/link de Power BI embed) e exclusão segura com validação de usuários vinculados.
- [x] **Grupos Pré-criados**: Grupos `Coleta` (com Power BI padrão) e `Infectantes` (pronto para cadastro de código/URL de incorporação) criados automaticamente via banco de dados.
- [x] **Edição e Exclusão de Usuários (`/dashboard/usuarios/`)**: Botões e modais de edição de dados (login, e-mail, perfil, grupo) e exclusão com auditoria.
- [x] **Associação Obrigatória a Grupos**: Usuários comuns (`user`) são obrigatoriamente vinculados a um grupo.
- [x] **Dashboard Dinâmico por Grupo (`/dashboard/`)**: Exibição dinâmica do Power BI correspondente ao grupo do usuário logado; administradores (`root` e `master`) contam com seletor rápido para alternar entre os grupos.
- [x] **Controle de Acesso**: Acesso a visualização e gestão de grupos restrito exclusivamente a `root` e `master`.

### 🔒 Segurança e Banco de Dados

- [x] Tabela `groups` criada e migração automática de `users.group_id`.
- [x] Sanitização e extração automática de URL a partir de tags `<iframe>` do Power BI coladas por administradores.
- [x] Registro de auditoria em `activity_logs` para operações de grupos (`create_group`, `edit_group`, `delete_group`) e edição de usuários (`edit_user`).

### 🔧 Técnico

- [x] Endpoints criados: `/api/groups/index.php`, `/api/groups/edit.php`, `/api/groups/delete.php` e `/api/users/edit.php`.
- [x] Componente `PowerBIViewer` parametrizado dinamicamente para aceitar URL de grupo com estado vazio amigável.
- [x] Build estático validado com sucesso com `npm run build` e `npm run lint`.

## [v1.1.0 (2026-08-17)](https://github.com/ricardosierra/ecoleta/compare/v1.0.4...v1.1.0)

### 🚀 Funcionalidades

- [x] Geração e redefinição de senhas temporárias com controle de permissões por perfil (`root` para todos, `master` apenas para usuários padrão)
- [x] Exclusão segura de usuários com confirmação em modal e regras de autorização (`root` para todos exceto a si próprio, `master` para usuários comuns)
- [x] Sistema completo de auditoria e histórico de logs de atividades (`activity_logs`) registrando logins, logouts, cadastros, alterações e exclusões
- [x] Nova visualização detalhada de histórico do usuário com badges visuais temáticos, executor, IP e identificação do dispositivo
- [x] Cópia em um clique da senha gerada diretamente na interface com feedback visual

### 🔒 Segurança e Banco de Dados

- [x] Criação e validação automática das tabelas `users`, `access_logs` e `activity_logs` via `db.php`
- [x] Exigência mandatória de troca de senha no primeiro login (`force_password_change`)
- [x] Validação dupla de perfis e permissões no backend (PHP) e no frontend (React)

### 🔧 Técnico

- [x] Endpoints criados: `/api/users/generate_password.php` e `/api/users/delete.php`
- [x] Endpoints atualizados para auditoria: `login.php`, `logout.php`, `change_password.php`, `users/index.php` e `users/logs.php`
- [x] Build estático regenerado e empacotado em `ecoleta-out.zip`
- [x] Validação executada com `npm run build` e `npm run lint`

## [v1.0.4 (2026-08-10)](https://github.com/ricardosierra/ecoleta/compare/v1.0.3...v1.0.4)

### 🎨 Melhorias

- [x] Painel BI público adicionado em `/dashboard` com embed do Power BI, recarregamento e modo tela cheia
- [x] Navegação e rodapé atualizados com acesso ao Painel BI e destaque correto do item Início
- [x] Indicadores ESG, logos de clientes e imagem da página Sobre atualizados para a identidade Ecoleva
- [x] Grade de clientes simplificada para melhorar leitura, responsividade e acessibilidade

### 🔒 Segurança

- [x] Autenticação simulada do dashboard removida: senhas e variáveis `NEXT_PUBLIC_*` não são usadas no client
- [x] Configurações de banco e FTP mantidas como placeholders em `.env.example`, sem identificadores ou credenciais reais

### 🔧 Técnico

- [x] Script `npm run deploy:ftp` adicionado para publicação com credenciais lidas exclusivamente do `.env` local
- [x] `robots.txt` configurado para não indexar `/dashboard/`
- [x] Build estático regenerado e empacotado em `ecoleta-out.zip`
- [x] Validação executada com `npm run build` e `npm run lint`

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
