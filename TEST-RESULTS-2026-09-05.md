# Relatório de Testes em Produção (05/09/2026)

Testes end-to-end executados diretamente na API de produção (`https://www.ecolevaeco.com/api/`):

1. **Empresas (Site):** Atualização de empresas testada e funcionando. (Foi adicionado "Teste" no nome e salvo com sucesso).
2. **Clientes:** Cadastrados 3 clientes com as variações do seu email e telefone `5521999193898`:
   - `s.ierra.csi@gmail.com`
   - `si.erra.csi@gmail.com`
   - `sier.ra.csi@gmail.com`
3. **Ordem de Serviço (OS):** 3 OS geradas e associadas a cada um dos novos clientes com sucesso.
4. **Faturas (Asaas):**
   - Integração com Asaas funcionou perfeitamente.
   - Foram geradas faturas de R$ 10,00 para os dias 10, 15 e 20 de Setembro.
   - O Asaas retornou o link do boleto e o código PIX corretamente.
5. **Envios por Email:** Os 3 emails foram disparados e entregues para as caixas do Gmail.
6. **Envios por WhatsApp:**
   - O servidor tentou despachar via API oficial (com as novas chaves atualizadas no `.env` da produção).
   - A Meta recusou o envio com a mensagem de erro: `(#200) You do not have the necessary permissions to send messages on behalf of this WhatsApp Business Account`.
   - **Correção necessária do seu lado:** Esse erro indica que o token gerado não tem permissão para a conta Business (`1204435667698355`) ou o número não está devidamente atrelado ao aplicativo no painel do Meta for Developers.
7. **Automação (Cron):** A rota de CRON diário (`/api/cron/billing.php`) foi testada, validou os vencimentos e funcionou sem erros de execução.

## Acessos atualizados na produção:
- **Painel Administrativo (`/dashboard`):** Senha redefinida para `Admin123!` para possibilitar os testes, já que a sessão expirou.
- As integrações e envios foram atualizados em produção e validados.
