<?php
declare(strict_types=1);

/**
 * Peças do cadastro de cliente que valem testar sem passar pelo endpoint.
 */

require_once __DIR__ . '/../asaas_lib.php';

/**
 * Grava o cliente local depois de ele já existir no Asaas.
 *
 * O cadastro cria o cliente remoto ANTES do INSERT, porque o id do Asaas precisa
 * estar na linha. O preço dessa ordem: se o INSERT falha (chave duplicada numa
 * corrida, banco fora do ar), o cliente continua existindo no Asaas, sem linha
 * nossa que o aponte, e ninguém sabe que ele está lá. Aqui a falha desfaz o
 * cadastro remoto antes de seguir.
 *
 * A remoção é "melhor esforço" e NUNCA troca o erro: quem sobe é sempre o
 * PDOException original, porque é por ele (`23000` = documento repetido) que o
 * endpoint escolhe a mensagem. Se a remoção também falhar, o id do cliente órfão
 * vai para o log, para alguém apagar à mão. Só o id: nome, e-mail e documento do
 * cliente não entram em log.
 *
 * @param array{name:string,email:string,whatsapp:string,document:?string,monthly_value:float,due_day:int,status:string} $cliente
 * @param (callable(string):mixed)|null $removeRemote remove um cliente do Asaas pelo id; padrão `asaasDeleteCustomer`
 * @return int o id da linha gravada
 * @throws PDOException o erro original do INSERT
 */
function clientsInsertAfterAsaas(PDO $db, array $cliente, string $asaasCustomerId, ?callable $removeRemote = null): int
{
    $removeRemote ??= 'asaasDeleteCustomer';

    try {
        $stmt = $db->prepare(
            'INSERT INTO clients (name, email, whatsapp, document, monthly_value, due_day, status, asaas_customer_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $cliente['name'],
            $cliente['email'],
            $cliente['whatsapp'],
            ($cliente['document'] ?? '') !== '' ? $cliente['document'] : null,
            $cliente['monthly_value'],
            $cliente['due_day'],
            $cliente['status'],
            $asaasCustomerId,
        ]);

        return (int) $db->lastInsertId();
    } catch (PDOException $e) {
        try {
            $removeRemote($asaasCustomerId);
        } catch (Throwable) {
            error_log(sprintf(
                'Clientes: o cadastro falhou no banco e o cliente %s ficou órfão no Asaas; remova pelo painel.',
                $asaasCustomerId
            ));
        }

        throw $e;
    }
}
