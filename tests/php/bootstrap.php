<?php
declare(strict_types=1);

/**
 * Bootstrap da suíte PHP.
 *
 * O backend é PHP 8 puro, com `require_once` e sem autoloader — então aqui
 * também não há um: as classes de apoio são carregadas na mão.
 */

define('ECOLETA_ROOT', dirname(__DIR__, 2));
define('ECOLETA_API_DIR', ECOLETA_ROOT . '/public/api');

require_once __DIR__ . '/Support/TestDatabase.php';
require_once __DIR__ . '/Support/Endpoint.php';
require_once __DIR__ . '/Support/EndpointResponse.php';

// A suíte fala português nas mensagens de log que compara; o resto do ambiente
// fica em UTC para as asserções de data não dependerem da máquina.
date_default_timezone_set('UTC');
