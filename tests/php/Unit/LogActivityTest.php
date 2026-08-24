<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ECOLETA_API_DIR . '/db.php';

/**
 * logActivity() — a trilha de auditoria de public/api/db.php.
 *
 * É o registro que responde "quem mudou o quê" quando alguém pergunta depois.
 * Duas propriedades importam: gravar o que se espera, e nunca derrubar a
 * operação que estava sendo auditada se a gravação falhar.
 */
final class LogActivityTest extends TestCase
{
    private TestDatabase $db;

    private string $errorLogFile;

    private string $previousErrorLog;

    protected function setUp(): void
    {
        $this->db = new TestDatabase();

        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Teste)';
        unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_CLIENT_IP']);

        // error_log próprio: os testes de falha conferem o que foi registrado.
        $this->errorLogFile = tempnam(sys_get_temp_dir(), 'ecoleta_log_');
        $this->previousErrorLog = (string) ini_get('error_log');
        ini_set('error_log', $this->errorLogFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        if (is_file($this->errorLogFile)) {
            unlink($this->errorLogFile);
        }
        $this->db->destroy();
    }

    public function testGravaORegistroEsperado(): void
    {
        logActivity(
            $this->db->pdo(),
            12,
            'edit_user',
            "Dados do usuário 'joao' (user) atualizados por chefe (master)",
            3,
            'chefe',
            'joao'
        );

        $rows = $this->db->rows('activity_logs');
        self::assertCount(1, $rows);

        $row = $rows[0];
        self::assertSame(12, (int) $row['user_id']);
        self::assertSame('joao', $row['target_login']);
        self::assertSame('edit_user', $row['action']);
        self::assertSame("Dados do usuário 'joao' (user) atualizados por chefe (master)", $row['description']);
        self::assertSame(3, (int) $row['performed_by_id']);
        self::assertSame('chefe', $row['performed_by_login']);
        self::assertSame('198.51.100.7', $row['ip_address']);
        self::assertSame('Mozilla/5.0 (Teste)', $row['user_agent']);
    }

    /**
     * delete_group audita uma ação sem usuário-alvo: user_id nulo tem que
     * passar, senão a exclusão de grupo ficaria sem registro.
     */
    public function testAceitaAcaoSemUsuarioAlvo(): void
    {
        logActivity($this->db->pdo(), null, 'delete_group', "Grupo 'Coleta' (ID 4) excluído por admin (root)", 1, 'admin', 'Coleta');

        $row = $this->db->rows('activity_logs')[0];
        self::assertNull($row['user_id']);
        self::assertSame('delete_group', $row['action']);
        self::assertSame('Coleta', $row['target_login']);
    }

    public function testDescricaoOpcionalFicaNula(): void
    {
        logActivity($this->db->pdo(), 1, 'login');

        $row = $this->db->rows('activity_logs')[0];
        self::assertNull($row['description']);
        self::assertNull($row['performed_by_id']);
        self::assertNull($row['performed_by_login']);
        self::assertNull($row['target_login']);
    }

    /** A coluna é VARCHAR(255); o corte tem que sair do PHP, não do banco. */
    public function testUserAgentEhCortadoEm255(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = str_repeat('x', 400);

        logActivity($this->db->pdo(), 1, 'login');

        self::assertSame(255, strlen((string) $this->db->rows('activity_logs')[0]['user_agent']));
    }

    public function testSemUserAgentGravaUnknown(): void
    {
        unset($_SERVER['HTTP_USER_AGENT']);

        logActivity($this->db->pdo(), 1, 'login');

        self::assertSame('unknown', $this->db->rows('activity_logs')[0]['user_agent']);
    }

    public function testIpAtrasDeProxyVemDoCabecalhoEncaminhado(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.55, 10.0.0.1';

        logActivity($this->db->pdo(), 1, 'login');

        self::assertSame('203.0.113.55', $this->db->rows('activity_logs')[0]['ip_address']);
    }

    /**
     * Cabeçalho forjado com lixo não pode virar IP gravado — a auditoria cai
     * para o próximo candidato válido.
     */
    public function testCabecalhoInvalidoCaiParaORemoteAddr(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'nao-e-um-ip';

        logActivity($this->db->pdo(), 1, 'login');

        self::assertSame('198.51.100.7', $this->db->rows('activity_logs')[0]['ip_address']);
    }

    /**
     * A auditoria é registro, não pré-requisito: se a tabela sumir, a operação
     * auditada continua. O contrário — exceção subindo daqui — desfaria uma
     * edição de usuário já gravada só porque o log falhou.
     */
    public function testFalhaDeGravacaoNaoInterrompeAOperacao(): void
    {
        $this->db->dropTable('activity_logs');

        logActivity($this->db->pdo(), 1, 'edit_user', 'qualquer coisa');

        self::assertStringContainsString(
            'Failed to write activity log',
            (string) file_get_contents($this->errorLogFile),
            'a falha precisa aparecer no log do servidor, não sumir'
        );
    }
}
