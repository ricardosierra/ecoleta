<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ECOLETA_API_DIR . '/authz.php';

/**
 * Regras de papel do servidor — public/api/authz.php.
 *
 * É o lado que decide de verdade: lib/authz.ts tem as mesmas regras, mas só
 * para escolher o que desenhar. tests/lib/authz.test.ts cobre aquele; este
 * cobre este, e as duas suítes exercitam a mesma tabela de casos de propósito.
 * Se um dos lados afrouxar sozinho, um dos dois arquivos quebra.
 */
final class AuthzTest extends TestCase
{
    // --- reconhecimento de papel -------------------------------------------

    #[DataProvider('papeisConhecidos')]
    public function testPapelConhecidoEhAceito(string $role): void
    {
        self::assertSame($role, apiNormalizeRole($role));
    }

    public static function papeisConhecidos(): array
    {
        return [['root'], ['master'], ['user']];
    }

    /**
     * Nada de aparar espaço ou baixar caixa: o resto do código compara com
     * `===`, e aceitar 'Root' aqui criaria um papel que passa na porta e é
     * tratado como desconhecido lá dentro.
     */
    #[DataProvider('papeisInvalidos')]
    public function testPapelDesconhecidoViraNull($value): void
    {
        self::assertNull(apiNormalizeRole($value));
    }

    public static function papeisInvalidos(): array
    {
        return [
            'caixa alta' => ['Root'],
            'com espaço' => [' root '],
            'vazio' => [''],
            'inexistente' => ['admin'],
            'nulo' => [null],
            'número' => [0],
            'array' => [['root']],
            'booleano' => [true],
        ];
    }

    // --- quem é administrador ----------------------------------------------

    public function testAdministradoresSaoRootEMaster(): void
    {
        self::assertTrue(apiRoleIsAdmin('root'));
        self::assertTrue(apiRoleIsAdmin('master'));
        self::assertFalse(apiRoleIsAdmin('user'));
        self::assertFalse(apiRoleIsAdmin(null));
    }

    public function testUsuarioComumNaoGerenciaUsuariosNemGrupos(): void
    {
        self::assertFalse(apiRoleCanManageUsers('user'));
        self::assertFalse(apiRoleCanManageGroups('user'));

        foreach (['root', 'master'] as $role) {
            self::assertTrue(apiRoleCanManageUsers($role), "{$role} deveria gerenciar usuários");
            self::assertTrue(apiRoleCanManageGroups($role), "{$role} deveria gerenciar grupos");
        }
    }

    // --- quem age sobre quem ------------------------------------------------

    public function testRootAgeSobreQualquerPapel(): void
    {
        foreach (['root', 'master', 'user'] as $target) {
            self::assertTrue(apiRoleCanActOnUser('root', $target));
        }
    }

    public function testMasterSoAgeSobreContasComuns(): void
    {
        self::assertTrue(apiRoleCanActOnUser('master', 'user'));
        self::assertFalse(apiRoleCanActOnUser('master', 'master'));
        self::assertFalse(apiRoleCanActOnUser('master', 'root'));
    }

    public function testUsuarioComumNaoAgeSobreNinguem(): void
    {
        foreach (['root', 'master', 'user'] as $target) {
            self::assertFalse(apiRoleCanActOnUser('user', $target));
        }
    }

    /**
     * Papel de alvo desconhecido não pode virar brecha: master editando alguém
     * cujo papel o banco devolveu torto é recusa, não permissão.
     */
    public function testMasterNaoAgeSobreAlvoDePapelDesconhecido(): void
    {
        self::assertFalse(apiRoleCanActOnUser('master', 'superadmin'));
        self::assertFalse(apiRoleCanActOnUser('master', null));
    }

    public function testEditarEGerarSenhaSeguemAMesmaRegra(): void
    {
        foreach (['root', 'master', 'user', null] as $actor) {
            foreach (['root', 'master', 'user'] as $target) {
                self::assertSame(
                    apiRoleCanEditUser($actor, $target),
                    apiRoleCanGeneratePassword($actor, $target),
                    sprintf('divergiu para %s sobre %s', $actor ?? 'null', $target)
                );
            }
        }
    }

    // --- exclusão -----------------------------------------------------------

    public function testNinguemSeExclui(): void
    {
        self::assertFalse(apiRoleCanDeleteUser('root', 7, 'root', 7));
        self::assertFalse(apiRoleCanDeleteUser('master', 7, 'user', 7));
    }

    public function testRootExcluiOutroRootMasterNao(): void
    {
        self::assertTrue(apiRoleCanDeleteUser('root', 1, 'root', 2));
        self::assertFalse(apiRoleCanDeleteUser('master', 1, 'root', 2));
        self::assertTrue(apiRoleCanDeleteUser('master', 1, 'user', 2));
    }

    public function testUsuarioComumNaoExcluiNinguem(): void
    {
        self::assertFalse(apiRoleCanDeleteUser('user', 1, 'user', 2));
        self::assertFalse(apiRoleCanDeleteUser('user', 1, 'root', 2));
    }

    // --- criação ------------------------------------------------------------

    public function testRootCriaUserEMasterMasNuncaOutroRoot(): void
    {
        self::assertSame(['user', 'master'], apiAssignableRolesOnCreate('root'));
        self::assertFalse(apiRoleCanAssignOnCreate('root', 'root'));
        self::assertTrue(apiRoleCanAssignOnCreate('root', 'master'));
        self::assertTrue(apiRoleCanAssignOnCreate('root', 'user'));
    }

    public function testMasterSoCriaContasComuns(): void
    {
        self::assertSame(['user'], apiAssignableRolesOnCreate('master'));
        self::assertTrue(apiRoleCanAssignOnCreate('master', 'user'));
        self::assertFalse(apiRoleCanAssignOnCreate('master', 'master'));
        self::assertFalse(apiRoleCanAssignOnCreate('master', 'root'));
    }

    public function testUsuarioComumNaoCriaNinguem(): void
    {
        self::assertSame([], apiAssignableRolesOnCreate('user'));
        self::assertFalse(apiRoleCanAssignOnCreate('user', 'user'));
    }

    public function testPapelPedidoInvalidoNaoEhAtribuivel(): void
    {
        self::assertFalse(apiRoleCanAssignOnCreate('root', 'Master'));
        self::assertFalse(apiRoleCanAssignOnCreate('root', ''));
        self::assertFalse(apiRoleCanAssignOnCreate('root', null));
    }

    // --- papel efetivo na edição -------------------------------------------

    /**
     * A regra que impede escalada de privilégio pelo corpo da requisição:
     * mesmo que o JSON peça 'root', uma edição feita por master grava 'user'.
     */
    public function testMasterNuncaPromoveNinguem(): void
    {
        self::assertSame('user', apiEffectiveRoleOnEdit('master', 'root', 'user'));
        self::assertSame('user', apiEffectiveRoleOnEdit('master', 'master', 'user'));
        self::assertSame('user', apiEffectiveRoleOnEdit('master', 'user', 'user'));
    }

    public function testRootGravaOPapelPedido(): void
    {
        self::assertSame('master', apiEffectiveRoleOnEdit('root', 'master', 'user'));
        self::assertSame('root', apiEffectiveRoleOnEdit('root', 'root', 'user'));
    }

    /**
     * A outra metade da trava da própria conta. apiRoleCanDeleteUser() já
     * impedia apagar a si próprio; sem esta, a mesma perda acontecia pela porta
     * do lado — o único root se rebaixava a 'user' e a instalação ficava sem
     * nenhum root, com install.php já autodesativado.
     */
    public function testNinguemMudaOProprioPapel(): void
    {
        self::assertSame('root', apiEffectiveRoleOnEdit('root', 'user', 'root', true));
        self::assertSame('root', apiEffectiveRoleOnEdit('root', 'master', 'root', true));
        self::assertSame('master', apiEffectiveRoleOnEdit('master', 'root', 'master', true));
    }

    public function testATravaValeSoParaAPropriaConta(): void
    {
        self::assertSame('user', apiEffectiveRoleOnEdit('root', 'user', 'root', false));
        self::assertSame('user', apiEffectiveRoleOnEdit('root', 'user', 'root'));
    }

    public function testPedidoInvalidoMantemOPapelAtual(): void
    {
        self::assertSame('master', apiEffectiveRoleOnEdit('root', 'Root', 'master'));
        self::assertSame('user', apiEffectiveRoleOnEdit('root', '', 'user'));
        self::assertSame('user', apiEffectiveRoleOnEdit('root', null, 'user'));
    }

    // --- grupo obrigatório --------------------------------------------------

    public function testApenasContaComumExigeGrupo(): void
    {
        self::assertTrue(apiRoleRequiresGroup('user'));
        self::assertFalse(apiRoleRequiresGroup('master'));
        self::assertFalse(apiRoleRequiresGroup('root'));
        self::assertFalse(apiRoleRequiresGroup('desconhecido'));
    }

    // --- histórico ----------------------------------------------------------

    public function testAdministradorVeOHistoricoDeQualquerConta(): void
    {
        self::assertTrue(apiRoleCanViewUserLogs('root', 1, 99));
        self::assertTrue(apiRoleCanViewUserLogs('master', 1, 99));
    }

    public function testContaComumSoVeOProprioHistorico(): void
    {
        self::assertTrue(apiRoleCanViewUserLogs('user', 42, 42));
        self::assertFalse(apiRoleCanViewUserLogs('user', 42, 43));
    }

    public function testSessaoSemPapelConhecidoNaoVeHistoricoNemOProprio(): void
    {
        self::assertFalse(apiRoleCanViewUserLogs(null, 42, 42));
        self::assertFalse(apiRoleCanViewUserLogs('Root', 42, 42));
    }

    // --- ator vindo da sessão ------------------------------------------------

    public function testAtorSaiDaSessaoComIdInteiro(): void
    {
        $_SESSION = ['user_id' => 7, 'role' => 'master', 'login' => 'chefe'];

        self::assertSame(['id' => 7, 'role' => 'master', 'login' => 'chefe'], apiSessionActor());
    }

    /** A sessão do PHP pode devolver o id como string; continua valendo. */
    public function testIdDeSessaoEmStringNumericaEhAceito(): void
    {
        $_SESSION = ['user_id' => '7', 'role' => 'root', 'login' => 'admin'];

        self::assertSame(7, apiSessionActor()['id']);
    }

    #[DataProvider('sessoesInvalidas')]
    public function testSessaoInvalidaNaoTemAtor(array $session): void
    {
        $_SESSION = $session;

        self::assertNull(apiSessionActor());
    }

    public static function sessoesInvalidas(): array
    {
        return [
            'vazia' => [[]],
            'sem id' => [['role' => 'root']],
            'sem papel' => [['user_id' => 1]],
            'papel desconhecido' => [['user_id' => 1, 'role' => 'superadmin']],
            'papel com caixa trocada' => [['user_id' => 1, 'role' => 'Root']],
            'id não numérico' => [['user_id' => 'abc', 'role' => 'root']],
        ];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }
}
