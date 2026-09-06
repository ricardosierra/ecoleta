# Auditoria de produção — Ecoleva — 05/09/2026

**Estado: parcial, aguardando dados do usuário e aprovação da Meta.**

Produção: https://www.ecolevaeco.com

## Verificado diretamente em produção

- Schema MySQL na versão 15. Nenhuma migration foi alterada ou aplicada nesta auditoria.
- Banco consultado: 23 empresas do site, nenhum cliente, nenhuma fatura e nenhuma OS.
- As páginas de clientes, faturas, empresas, OS e WhatsApp respondem HTTP 200.
- APIs públicas de empresas e indicadores respondem HTTP 200 (23 empresas e 6 indicadores).
- APIs de clientes, faturas e OS recusam acesso sem autenticação (403).
- Segredo do webhook do Asaas configurado e receptor validado com evento sem efeitos.
- Webhook Asaas cadastrado, ativo e sequencial, apontando para a API de produção. ID: ca1795d1-5a77-49ec-8f27-27de0c6cac28.
- Aplicativo Meta conectado à conta WhatsApp; assinatura de mensagens ativada, preservando os demais campos assinados.
- Token do WhatsApp válido, sem expiração informada pelo provedor. Número do robô: +55 21 97667-1216.
- Templates `ecoleva_ordem_servico` e `ecoleva_fatura_mensal` enviados à Meta como UTILITY, em pt_BR. Última consulta: PENDING.
- Mensagem de teste enviada pelo SMTP do servidor de produção e encontrada na caixa de entrada de sierra.csi@gmail.com, assunto “Teste de produção Ecoleva — 05/09/2026”, às 21:06. Referência QA-20260905.
- Diagnóstico temporário protegido por token e expiração removido após a execução. Não criou usuários, clientes, faturas ou OS.
- Nove arquivos da API publicados e comparados byte a byte com as versões locais.
- Cron HTTP autenticado executado em produção: HTTP 200, zero faturas e zero lembretes, consistente com o banco vazio.
- Bundles JS/CSS das páginas de clientes, faturas e empresas verificados: todos responderam HTTP 200.

## Correções publicadas

- Empresas: upload com prévia de logo, edição de nome/logo, erros visíveis, indicação de processamento e atualização de status sem recarregar a lista.
- Clientes: edição dos dados de contato, documento, mensalidade e vencimento; sincronização com Asaas; normalização de telefone também na edição; validação de email e valor negativo; documento ausente armazenado como NULL; checagem de duplicação antes de criar registro externo.
- Faturas: criação manual e envio pelo painel, nova tentativa de canais pendentes, data sem deslocamento de fuso e identificação de cancelamento/estorno.
- Emissão: trava entre processos e referência externa para recuperar cobrança criada antes de uma interrupção; persistência da fatura antes da consulta Pix; imagem base64 não é mais gravada em VARCHAR(255).
- Entrega: email e WhatsApp independentes; registro persistente por canal; falhas não contabilizadas como envio; tentativa interrompida exige verificação antes de reenviar; falha assíncrona de WhatsApp permite nova tentativa após registro pelo webhook.
- Cron: utiliza o mesmo fluxo de emissão/entrega, considera pendentes e vencidas nos lembretes, recusa envio de clientes inativos, informa falhas com HTTP 502 e aceita execução CLI no servidor.
- OS: valida cliente existente, data e quantidades antes da gravação.
- Webhook Asaas: cancelamento/estorno/contestação não voltam para a fila de cobrança como pendentes.
- URLs de compartilhamento fixadas no domínio de produção.
- Script legado `api/apply_mig_13.php` impedido de executar alterações de banco via HTTP.

## Validação automatizada

- Suíte frontend: 182 testes passaram; mais 2 testes novos da tela de faturas passaram separadamente (184 no conjunto).
- Suíte backend: 313 testes passaram; mais 2 testes de edição de empresas passaram separadamente (315 no conjunto).
- Após as últimas mudanças: testes específicos de entrega, empresas e cron passaram.
- TypeScript, ESLint, build de produção e 24 verificações do runner de migrations passaram.
- Testes de erro e recuperação incluem Pix indisponível, recuperação de pagamento externo, envio de email recusado e nova tentativa sem duplicar um envio confirmado.

## Pendências para cumprir integralmente o pedido

1. **Acesso de administrador:** as sessões disponíveis abriram na tela de login. Necessário entrar no dashboard ou fornecer as credenciais existentes para testar os fluxos reais pela interface.
2. **CPF/CNPJ autorizado:** o Asaas exige documento para boleto real. Não foram usados documentos fictícios nem documentos de terceiros. O schema atual também exige documento único por cliente; vários cadastros com o mesmo pagador precisam de uma decisão de modelagem ou documentos distintos autorizados.
3. **Cenários ainda não cadastrados:** proposta inicial de três clientes com s.ierra.csi@gmail.com, si.erra.csi@gmail.com e sier.ra.csi@gmail.com, telefone 5521999193898, valores R$ 5,00 / R$ 7,50 / R$ 9,90 e vencimentos em dias consecutivos futuros, definidos no momento da execução. Esses registros NÃO existem ainda.
4. **WhatsApp:** aprovação dos dois templates ou abertura da janela de atendimento por mensagem do usuário ao robô. Recebimento real de OS e fatura pelo WhatsApp ainda não confirmado.
5. **Agendamento do servidor:** execução HTTP/CLI preparada; a existência/configuração de cron diário na Hostinger ainda não foi verificada. A automação mensal não pode ser declarada validada até confirmar esse agendamento e observar suas execuções.
6. **OS e fatura reais:** gerar, enviar, abrir documento/link, conferir destinatários, valores/datas e persistência após recarregar a interface. Nenhum pagamento real foi realizado.

As variações com pontos do endereço Gmail devem convergir para a mesma caixa postal, mas a entrega de cada cenário ainda precisa ser observada. Não há evidência suficiente para afirmar funcionamento de ponta a ponta em 100% dos módulos.

## Continuidade

O checkout já tinha alterações em andamento no começo do trabalho; elas foram preservadas. Nenhum commit ou PR foi criado. Backups das versões remotas e manifesto da publicação estão em `/tmp/ecoleta-production-audit/` nesta máquina, com as credenciais mantidas somente em arquivos locais restritos. A publicação de frontend colocou os bundles antes das páginas; os arquivos da API foram trocados por rename após upload.
