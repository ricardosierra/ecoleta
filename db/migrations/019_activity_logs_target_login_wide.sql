-- 019_activity_logs_target_login_wide.sql — activity_logs.target_login passa de VARCHAR(50) para VARCHAR(255).
--
-- A entrega de fatura grava nessa coluna o id da mensagem do WhatsApp (o "wamid"), que
-- tem mais de 50 caracteres. Em MySQL estrito o UPDATE que marca a tentativa como
-- entregue falhava DEPOIS de a mensagem sair: a linha ficava em `billing_attempt` para
-- sempre, e todo ciclo seguinte devolvia "resultado incerto" para aquele canal, sem
-- botão que destravasse. A coluna nasceu com 50 porque guardava só o login do usuário
-- alvo de uma ação administrativa.
--
-- Idempotente: MODIFY para o mesmo tipo repetido não altera nada.

ALTER TABLE activity_logs MODIFY COLUMN target_login VARCHAR(255) NULL;
