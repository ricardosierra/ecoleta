<?php
declare(strict_types=1);

// Carrega as variáveis de ambiente geradas pelo deploy
$envFile = __DIR__ . '/env.php';
if (file_exists($envFile)) {
    require_once $envFile;
} else {
    // Fallback para desenvolvimento local caso as variáveis não existam no arquivo
    if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
    if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'ecoleta');
    if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');
    if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: '');
    if (!defined('NEXT_PUBLIC_DASHBOARD_PASSWORD')) define('NEXT_PUBLIC_DASHBOARD_PASSWORD', getenv('NEXT_PUBLIC_DASHBOARD_PASSWORD') ?: 'ecoleta2026');
}

function getDbConnection(): PDO {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        return new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Falha na conexão com o banco de dados.']);
        exit;
    }
}
