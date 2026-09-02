<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ECOLETA_API_DIR . '/asaas_lib.php';

final class AsaasLibTest extends TestCase
{
    public function testAsaasUpdateCustomerExiste(): void
    {
        self::assertTrue(function_exists('asaasUpdateCustomer'));
    }

    public function testAsaasUpdateCustomerLancaExcecaoSemApiKey(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ASAAS_API_KEY não configurada.');

        asaasUpdateCustomer('cus_test123', ['mobilePhone' => '5511999999999']);
    }
}
