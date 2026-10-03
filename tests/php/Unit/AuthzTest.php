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

    // --- trava de troca de senha --------------------------------------------

    /**
     * Travar a troca de quem ainda não trocou a senha temporária a deixa presa
     * nela: force_password_change manda trocar, a trava faz change_password.php
     * responder 403. Espelha `canTogglePasswordLock(root, alvo)` em
     * lib/authz.ts — a mesma tabela está em tests/lib/authz.test.ts.
     *
     * @return array<string, array{0:bool, 1:bool, 2:bool}>
     */
    public static function alternarATravaDeSenha(): array
    {
        return [
            // travada hoje, troca pendente, pode alternar?
            'travar conta saudável' => [false, false, true],
            'destravar' => [true, false, true],
            'destravar conta que já estava presa' => [true, true, true],
            'travar quem tem senha temporária' => [false, true, false],
        ];
    }

    #[DataProvider('alternarATravaDeSenha')]
    public function testRootAlternaATravaSoQuandoNaoPrendeAPessoa(bool $travada, bool $pendente, bool $esperado): void
    {
        // Alternar = o contrário do estado de hoje, sem redefinir a senha na mesma chamada.
        $pode = apiRoleCanTogglePasswordLock('root')
            && apiPasswordLockAllowed($travada, !$travada, false, $pendente);

        self::assertSame($esperado, $pode);
    }

    public function testQuemNaoEhRootNuncaAlternaATrava(): void
    {
        foreach (['master', 'user', null] as $papel) {
            self::assertFalse(apiRoleCanTogglePasswordLock($papel));
        }
    }

    /** As combinações que a edição pode pedir, uma a uma. */
    public function testTravaNaEdicaoNuncaPrendeQuemPrecisaTrocarASenha(): void
    {
        // [travada hoje, quer travada, redefine a senha, troca pendente] => permitido
        $casos = [
            [false, true, true, false, false],   // gerar senha e travar na mesma chamada
            [false, true, false, true, false],   // travar quem ainda tem a temporária
            [false, true, false, false, true],   // travar conta saudável
            [false, false, true, false, true],   // gerar senha sem travar
            [true, false, true, true, true],     // destravar e gerar senha: o caminho legítimo
            [true, true, false, true, true],     // já travada e continua: não é ativação
            [true, true, false, false, true],
            [false, false, false, true, true],   // não mexe na trava
        ];

        foreach ($casos as [$travada, $quer, $redefine, $pendente, $esperado]) {
            self::assertSame(
                $esperado,
                apiPasswordLockAllowed($travada, $quer, $redefine, $pendente),
                sprintf('travada=%d quer=%d redefine=%d pendente=%d', $travada, $quer, $redefine, $pendente)
            );
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

    // --- conciliação com o banco ----------------------------------------------

    /** @return array<string,mixed> */
    private static function linhaDeUsuario(array $troca = []): array
    {
        return array_merge([
            'id' => 7,
            'login' => 'chefe',
            'role' => 'master',
            'password_hash' => 'hash-vigente',
            'force_password_change' => 0,
        ], $troca);
    }

    public function testBancoAusenteInvalidaASessao(): void
    {
        self::assertNull(apiReconcileActor(null, null));
        self::assertNull(apiReconcileActor(null, apiPasswordFingerprint('hash-vigente')));
    }

    /** O papel que vale é o da linha do banco, não o que a sessão guardou. */
    public function testPapelVemDaLinhaDoBanco(): void
    {
        $_SESSION = ['user_id' => 7, 'role' => 'root', 'login' => 'antigo'];

        $ator = apiReconcileActor(self::linhaDeUsuario(['role' => 'user']), null);

        self::assertSame('user', $ator['role']);
        self::assertSame('chefe', $ator['login']);
        self::assertSame(7, $ator['id']);
    }

    public function testPapelDesconhecidoNoBancoInvalidaASessao(): void
    {
        self::assertNull(apiReconcileActor(self::linhaDeUsuario(['role' => 'superadmin']), null));
        self::assertNull(apiReconcileActor(self::linhaDeUsuario(['role' => 'Root']), null));
        self::assertNull(apiReconcileActor(self::linhaDeUsuario(['role' => null]), null));
    }

    public function testSenhaTrocadaDepoisDoLoginInvalidaASessao(): void
    {
        $antiga = apiPasswordFingerprint('hash-antigo');

        self::assertNull(apiReconcileActor(self::linhaDeUsuario(), $antiga));
    }

    public function testSenhaIntactaMantemASessao(): void
    {
        $vigente = apiPasswordFingerprint('hash-vigente');

        $ator = apiReconcileActor(self::linhaDeUsuario(), $vigente);

        self::assertNotNull($ator);
        self::assertSame($vigente, $ator['fingerprint']);
    }

    /** Sessão de antes da regra existir não tem impressão digital: é adotada, não derrubada. */
    public function testSessaoSemImpressaoDigitalEhAdotada(): void
    {
        foreach ([null, '', 123, ['x']] as $valor) {
            $ator = apiReconcileActor(self::linhaDeUsuario(), $valor);

            self::assertNotNull($ator, 'impressão digital ausente derrubou a sessão: ' . json_encode($valor));
            self::assertSame(apiPasswordFingerprint('hash-vigente'), $ator['fingerprint']);
        }
    }

    public function testSenhaTemporariaPendenteVemDaLinhaDoBanco(): void
    {
        self::assertFalse(apiReconcileActor(self::linhaDeUsuario(['force_password_change' => 0]), null)['force_password_change']);
        self::assertTrue(apiReconcileActor(self::linhaDeUsuario(['force_password_change' => 1]), null)['force_password_change']);
        self::assertTrue(apiReconcileActor(self::linhaDeUsuario(['force_password_change' => '1']), null)['force_password_change']);
    }

    public function testImpressaoDigitalNaoEhOHashEMudaComEle(): void
    {
        self::assertNotSame('hash-vigente', apiPasswordFingerprint('hash-vigente'));
        self::assertNotSame(apiPasswordFingerprint('a'), apiPasswordFingerprint('b'));
        self::assertSame(apiPasswordFingerprint('a'), apiPasswordFingerprint('a'));
    }

    // ── Painel de WhatsApp ──────────────────────────────────────────────────

    /**
     * A regra tem DUAS condições, e cada caso abaixo derruba uma delas. Espelha
     * `canViewWhatsAppPanel()` em lib/authz.ts — os dois arquivos exercitam a
     * mesma tabela de propósito.
     */
    #[DataProvider('acessosAoPainelDeWhatsApp')]
    public function testAcessoAoPainelDeWhatsApp(?string $role, ?string $email, bool $esperado): void
    {
        self::assertSame($esperado, apiRoleCanViewWhatsAppPanel($role, $email));
    }

    public static function acessosAoPainelDeWhatsApp(): array
    {
        return [
            'root na lista' => ['root', 'sierra.csi@gmail.com', true],
            'root na lista em caixa alta' => ['root', 'Sierra.CSI@Gmail.com', true],
            'root na lista com espaço' => ['root', '  sierra.csi@gmail.com  ', true],
            'root fora da lista' => ['root', 'outro@exemplo.com', false],
            'root sem e-mail' => ['root', null, false],
            'root com e-mail vazio' => ['root', '', false],
            'master liberado' => ['master', 'cliente@exemplo.com', true],
            'master sem e-mail' => ['master', null, true],
            'user na lista' => ['user', 'sierra.csi@gmail.com', false],
            'sem papel' => [null, 'sierra.csi@gmail.com', false],
            'papel desconhecido' => ['superadmin', 'sierra.csi@gmail.com', false],
            'papel com caixa trocada' => ['Root', 'sierra.csi@gmail.com', false],
        ];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }
}
