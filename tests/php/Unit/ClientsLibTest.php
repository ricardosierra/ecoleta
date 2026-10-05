<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ECOLETA_API_DIR . '/clients/clients_lib.php';

/**
 * O passo que vem DEPOIS de o cliente existir no Asaas.
 *
 * O cadastro cria o cliente remoto antes de gravar a linha local (o id do Asaas
 * precisa estar na linha). Se o INSERT falhar, sobrava um cadastro órfão no
 * Asaas, que ninguém via. O endpoint em si não dá para exercitar sem rede, então
 * o que a suíte cobre é a função que ele chama, com a remoção remota injetada.
 */
final class ClientsLibTest extends TestCase
{
    private TestDatabase $db;

    private string $logFile;

    private string|false $logAnterior;

    protected function setUp(): void
    {
        $this->db = new TestDatabase();

        // error_log vai para um arquivo da própria suíte: a saída fica limpa e o
        // teste consegue conferir o que foi (e o que não foi) registrado.
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'ecoleta_clients_log_');
        $this->logAnterior = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        if ($this->logAnterior !== false) {
            ini_set('error_log', $this->logAnterior);
        }
        @unlink($this->logFile);
        $this->db->destroy();
    }

    /** @return array<string,mixed> */
    private function cliente(?string $document = '11144477735'): array
    {
        return [
            'name' => 'Cliente Sigiloso',
            'email' => 'sigiloso@exemplo.com.br',
            'whatsapp' => '5521999887766',
            'document' => $document,
            'monthly_value' => 250.0,
            'due_day' => 10,
            'status' => 'active',
        ];
    }

    private function log(): string
    {
        return (string) file_get_contents($this->logFile);
    }

    public function testGravaOClienteComOIdDoAsaasSemTocarNoCadastroRemoto(): void
    {
        $id = clientsInsertAfterAsaas(
            $this->db->pdo(),
            $this->cliente(),
            'cus_ok',
            static function (): void {
                self::fail('Não se remove do Asaas um cadastro que foi gravado.');
            }
        );

        $linha = $this->db->rows('clients')[0];
        self::assertSame($id, (int) $linha['id']);
        self::assertSame('cus_ok', $linha['asaas_customer_id']);
        self::assertSame('11144477735', $linha['document']);
    }

    public function testDocumentoVazioViraNullParaNaoColidirComOutrosSemDocumento(): void
    {
        clientsInsertAfterAsaas($this->db->pdo(), $this->cliente(''), 'cus_a', static fn () => null);
        clientsInsertAfterAsaas($this->db->pdo(), $this->cliente(''), 'cus_b', static fn () => null);

        self::assertSame(2, $this->db->count('clients'));
        self::assertNull($this->db->rows('clients')[0]['document']);
    }

    public function testFalhaNoInsertRemoveOClienteDoAsaasERelancaOErroOriginal(): void
    {
        // Documento repetido: o mesmo CPF que já está na carteira.
        $this->db->seedClient('Já existe', 0.0, 10, 'active', null, null, null, '11144477735');

        $removidos = [];
        $erro = null;
        try {
            clientsInsertAfterAsaas(
                $this->db->pdo(),
                $this->cliente(),
                'cus_orfao',
                static function (string $id) use (&$removidos): array {
                    $removidos[] = $id;

                    return ['deleted' => true];
                }
            );
        } catch (PDOException $e) {
            $erro = $e;
        }

        self::assertInstanceOf(PDOException::class, $erro, 'o erro do banco precisa chegar ao endpoint');
        self::assertSame('23000', (string) $erro->getCode(), 'o endpoint decide a mensagem por este código');
        self::assertSame(['cus_orfao'], $removidos);
        self::assertSame(1, $this->db->count('clients'), 'nada foi gravado');
    }

    public function testFalhaNaRemocaoNaoMascaraOErroOriginalENemVazaDadoPessoal(): void
    {
        $this->db->seedClient('Já existe', 0.0, 10, 'active', null, null, null, '11144477735');

        $erro = null;
        try {
            clientsInsertAfterAsaas(
                $this->db->pdo(),
                $this->cliente(),
                'cus_orfao',
                static function (): void {
                    throw new RuntimeException('Asaas fora do ar para Cliente Sigiloso');
                }
            );
        } catch (Throwable $e) {
            $erro = $e;
        }

        // Quem sobe é o erro do banco, e não o da remoção: mascará-lo trocaria a
        // mensagem "documento já existe" por um 500 sem explicação.
        self::assertInstanceOf(PDOException::class, $erro);
        self::assertSame('23000', (string) $erro->getCode());

        // O que sobrou no Asaas precisa estar no log para alguém limpar à mão: o id
        // basta. Nome e e-mail do cliente não entram.
        self::assertStringContainsString('cus_orfao', $this->log());
        self::assertStringNotContainsString('Sigiloso', $this->log());
        self::assertStringNotContainsString('sigiloso@exemplo.com.br', $this->log());
    }
}
