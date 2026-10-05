<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ECOLETA_API_DIR . '/security.php';
require_once ECOLETA_API_DIR . '/auth/identity_lib.php';

/**
 * Funções puras de identidade (auth/identity_lib.php) e o validador de URL
 * https (security.php). Os endpoints que as usam têm os testes de ponta a ponta
 * em UsersEndpointTest e GroupsEndpointTest; aqui ficam as bordas.
 */
final class IdentityLibTest extends TestCase
{
    #[DataProvider('loginsDeFormatoValido')]
    public function testLoginDeFormatoValido(string $login): void
    {
        self::assertTrue(apiLoginFormatIsValid($login), $login);
    }

    public static function loginsDeFormatoValido(): array
    {
        return [['abc'], ['a.b-c_d'], ['Maria.Silva'], ['x1y2z3'], [str_repeat('a', 50)]];
    }

    #[DataProvider('loginsDeFormatoInvalido')]
    public function testLoginDeFormatoInvalido(string $login): void
    {
        self::assertFalse(apiLoginFormatIsValid($login), json_encode($login));
    }

    public static function loginsDeFormatoInvalido(): array
    {
        return [
            'vazio' => [''],
            'dois caracteres' => ['ab'],
            'cinquenta e um' => [str_repeat('a', 51)],
            'espaço' => ['a b c'],
            'arroba' => ['a@b.com'],
            'acento' => ['joão'],
            // O `$` do PCRE aceitaria a quebra de linha final; `\z` não.
            'quebra de linha no fim' => ["joao\n"],
            'quebra de linha no meio' => ["jo\nao"],
            'byte nulo' => ["joao\0"],
        ];
    }

    #[DataProvider('urlsHttpsValidas')]
    public function testUrlHttpsValida(string $url): void
    {
        self::assertTrue(apiIsHttpsUrl($url), $url);
    }

    public static function urlsHttpsValidas(): array
    {
        return [
            ['https://app.powerbi.com/view?r=abc'],
            ['HTTPS://app.powerbi.com/view?r=abc'],
            ['https://app.powerbi.com:443/reportEmbed?reportId=1&autoAuth=true'],
            ['https://exemplo.com.br/a/b/c#secao'],
        ];
    }

    #[DataProvider('urlsHttpsInvalidas')]
    public function testUrlHttpsInvalida(string $url): void
    {
        self::assertFalse(apiIsHttpsUrl($url), $url);
    }

    public static function urlsHttpsInvalidas(): array
    {
        return [
            'vazia' => [''],
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/html;base64,PHNjcmlwdD4='],
            'http' => ['http://exemplo.com'],
            'relativa' => ['/view?r=abc'],
            'sem host' => ['https://'],
            'credencial' => ['https://user:senha@exemplo.com/'],
            'só usuário' => ['https://app.powerbi.com@evil.example/'],
            'com espaço' => ['https://exemplo.com/a b'],
            'com quebra de linha' => ["https://exemplo.com/\nBcc: x"],
            'enorme' => ['https://exemplo.com/' . str_repeat('a', 2100)],
        ];
    }

    public function testMensagemDeColisaoCobreTodosOsTipos(): void
    {
        $mensagens = [];
        foreach (['login', 'email', 'login_is_email', 'email_is_login'] as $tipo) {
            $mensagens[$tipo] = apiIdentityClashMessage($tipo);
        }

        self::assertCount(4, array_unique($mensagens), 'cada tipo de colisão precisa de uma mensagem própria');
    }
}
