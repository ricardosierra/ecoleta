<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';

// Carrega as variáveis de ambiente geradas pelo deploy (arquivo fora do Git).
$envFile = __DIR__ . '/env.php';
if (file_exists($envFile)) {
    require_once $envFile;
} else {
    // Sem env.php só restam as variáveis de ambiente do servidor. Nenhum segredo
    // tem valor embutido: o que não estiver definido faz o recurso falhar fechado.
    error_log('public/api/env.php ausente — usando apenas variáveis de ambiente do servidor.');
    if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
    if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'ecoleta');
    if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');
    if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: '');
}

function getDbConnection(): PDO {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $db = new PDO($dsn, DB_USER, DB_PASS, $options);
        ensureTablesExist($db);
        return $db;
    } catch (PDOException $e) {
        error_log('Falha na conexão com o banco de dados: ' . $e->getMessage());
        apiJsonResponse(500, ['error' => 'Falha na conexão com o banco de dados.']);
    }
}

function ensureTablesExist(PDO $db): void {
    static $ensured = false;
    if ($ensured) return;

    try {
        // Tabela de Grupos (ex: Coleta, Infectantes)
        $db->exec("
            CREATE TABLE IF NOT EXISTS `groups` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL UNIQUE,
                powerbi_url TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                login VARCHAR(50) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                email VARCHAR(100) NULL,
                role ENUM('root', 'master', 'user') NOT NULL DEFAULT 'user',
                group_id INT NULL,
                force_password_change TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Migração para adicionar group_id na tabela users se ela já existia antes
        try {
            $checkColumn = $db->query("SHOW COLUMNS FROM users LIKE 'group_id'");
            if ($checkColumn && $checkColumn->rowCount() === 0) {
                $db->exec("ALTER TABLE users ADD COLUMN group_id INT NULL AFTER role");
            }
        } catch (\Throwable $e) {
            // Ignora se não for possível verificar colunas
        }

        // Seed dos grupos padrão (Coleta e Infectantes)
        try {
            $defaultPbi = defined('NEXT_PUBLIC_POWERBI_URL') ? NEXT_PUBLIC_POWERBI_URL : (getenv('NEXT_PUBLIC_POWERBI_URL') ?: '');
            
            $stmt = $db->prepare("SELECT id FROM `groups` WHERE name = ? LIMIT 1");
            
            // Grupo Coleta
            $stmt->execute(['Coleta']);
            if (!$stmt->fetch()) {
                $insertGroup = $db->prepare("INSERT INTO `groups` (name, powerbi_url) VALUES (?, ?)");
                $insertGroup->execute(['Coleta', $defaultPbi]);
            }

            // Grupo Infectantes
            $stmt->execute(['Infectantes']);
            if (!$stmt->fetch()) {
                $insertGroup = $db->prepare("INSERT INTO `groups` (name, powerbi_url) VALUES (?, ?)");
                $insertGroup->execute(['Infectantes', '']);
            }
        } catch (\Throwable $e) {
            error_log("Group seeding notice: " . $e->getMessage());
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS access_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                user_agent VARCHAR(255) NULL,
                logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_access_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Contador de tentativas de login por par (login, IP) — base do rate limit
        $db->exec("
            CREATE TABLE IF NOT EXISTS login_throttle (
                id INT AUTO_INCREMENT PRIMARY KEY,
                bucket CHAR(64) NOT NULL,
                scope VARCHAR(16) NOT NULL,
                failures INT UNSIGNED NOT NULL DEFAULT 0,
                first_failure_at DATETIME NOT NULL,
                last_failure_at DATETIME NOT NULL,
                blocked_until DATETIME NULL,
                UNIQUE KEY uniq_login_throttle_bucket (bucket),
                INDEX idx_login_throttle_blocked (blocked_until)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS activity_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                target_login VARCHAR(50) NULL,
                action VARCHAR(50) NOT NULL,
                description VARCHAR(255) NULL,
                performed_by_id INT NULL,
                performed_by_login VARCHAR(50) NULL,
                ip_address VARCHAR(45) NOT NULL,
                user_agent VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_activity_user (user_id),
                INDEX idx_activity_action (action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (\Throwable $e) {
        // Table creation or verification error logged without crashing
        error_log("Database initialization notice: " . $e->getMessage());
    }

    $ensured = true;
}

function logActivity(
    PDO $db,
    ?int $userId,
    string $action,
    ?string $description = null,
    ?int $performedById = null,
    ?string $performedByLogin = null,
    ?string $targetLogin = null
): void {
    try {
        ensureTablesExist($db);

        $ip = apiClientIp();
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);

        $stmt = $db->prepare("
            INSERT INTO activity_logs 
            (user_id, target_login, action, description, performed_by_id, performed_by_login, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $targetLogin,
            $action,
            $description,
            $performedById,
            $performedByLogin,
            $ip,
            $ua
        ]);
    } catch (\Throwable $e) {
        error_log("Failed to write activity log: " . $e->getMessage());
    }
}

