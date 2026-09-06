<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ECOLETA_API_DIR . '/clients/phone_lib.php';

/**
 * Testes para a biblioteca de normalização de telefone/WhatsApp (public/api/clients/phone_lib.php).
 * Espelha as regras testadas no frontend em tests/lib/phone.test.ts.
 */
final class PhoneLibTest extends TestCase
{
    public function testNormalizaNumeroComDdiEZeroNoDdd14Digitos(): void
    {
        // Critério de Aceite
        self::assertSame('5521999887766', normalizePhone('+55 (021) 99988-7766'));
        self::assertSame('5521999887766', normalizePhone('55021999887766'));
        self::assertSame('5511987654321', normalizePhone('+55 (011) 98765-4321'));
    }

    public function testNormalizaNumeroCelularComDdiSemZeroNoDdd13Digitos(): void
    {
        self::assertSame('5521999887766', normalizePhone('+55 (21) 99988-7766'));
        self::assertSame('5521999887766', normalizePhone('5521999887766'));
    }

    public function testNormalizaNumeroFixoComDdiEZeroNoDdd13Digitos(): void
    {
        self::assertSame('552133445566', normalizePhone('+55 (021) 3344-5566'));
        self::assertSame('552133445566', normalizePhone('5502133445566'));
    }

    public function testNormalizaNumeroFixoComDdiSemZeroNoDdd12Digitos(): void
    {
        self::assertSame('552133445566', normalizePhone('+55 (21) 3344-5566'));
        self::assertSame('552133445566', normalizePhone('552133445566'));
    }

    public function testNormalizaNumeroCelularLocalSemDdi11Digitos(): void
    {
        self::assertSame('5521999887766', normalizePhone('(21) 99988-7766'));
        self::assertSame('5521999887766', normalizePhone('21999887766'));
    }

    public function testNormalizaNumeroCelularLocalComZeroNoDddSemDdi12Digitos(): void
    {
        self::assertSame('5521999887766', normalizePhone('(021) 99988-7766'));
        self::assertSame('5521999887766', normalizePhone('021999887766'));
    }

    public function testNormalizaNumeroFixoLocalSemDdi10Digitos(): void
    {
        self::assertSame('552133445566', normalizePhone('(21) 3344-5566'));
        self::assertSame('552133445566', normalizePhone('2133445566'));
    }

    public function testNormalizaNumeroFixoLocalComZeroNoDddSemDdi11Digitos(): void
    {
        self::assertSame('552133445566', normalizePhone('(021) 3344-5566'));
        self::assertSame('552133445566', normalizePhone('02133445566'));
    }

    public function testCompletaODddPadraoEmCelularDeNoveDigitos(): void
    {
        self::assertSame('5521999887766', normalizePhone('99988-7766'));
        self::assertSame('5521999887766', normalizePhone('999887766'));
    }

    public function testCompletaODddPadraoEmFixoDeOitoDigitos(): void
    {
        self::assertSame('552133445566', normalizePhone('3344-5566'));
        self::assertSame('552133445566', normalizePhone('33445566'));
    }

    public function testRetornaStringVaziaParaEntradasInvalidasOuVazias(): void
    {
        self::assertSame('', normalizePhone(''));
        self::assertSame('', normalizePhone(null));
        self::assertSame('', normalizePhone('   '));
        self::assertSame('', normalizePhone('abc-def'));
    }

    public function testAliasesFuncionamIdenticamente(): void
    {
        self::assertSame('5521999887766', normalizeWhatsApp('+55 (021) 99988-7766'));
        self::assertSame('5521999887766', apiNormalizePhone('+55 (021) 99988-7766'));
    }
}
