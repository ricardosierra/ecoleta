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
        $db = new PDO($dsn, DB_USER, DB_PASS, $options);
        ensureTablesExist($db);
        return $db;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Falha na conexão com o banco de dados.']);
        exit;
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
        $db->exec("
            CREATE TABLE IF NOT EXISTS site_indicators (
                id INT AUTO_INCREMENT PRIMARY KEY,
                key_name VARCHAR(100) NOT NULL UNIQUE,
                label VARCHAR(255) NOT NULL,
                value VARCHAR(100) NOT NULL,
                numeric_value INT NULL,
                category VARCHAR(50) NOT NULL DEFAULT 'geral',
                order_index INT NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_indicator_key (key_name),
                INDEX idx_indicator_category (category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Seed dos indicadores padrão caso a tabela esteja vazia
        try {
            $countStmt = $db->query("SELECT COUNT(*) AS total FROM site_indicators");
            $totalIndicators = $countStmt ? (int)$countStmt->fetchColumn() : 0;
            if ($totalIndicators === 0) {
                $defaultIndicators = [
                    // Home
                    ['key_name' => 'home_donut_desvio', 'label' => 'Desvio de aterro (%)', 'value' => '92%', 'numeric_value' => 92, 'category' => 'home', 'order_index' => 1],
                    ['key_name' => 'home_reducao_custo', 'label' => 'Redução de custo operacional', 'value' => '↓R$', 'numeric_value' => null, 'category' => 'home', 'order_index' => 2],
                    ['key_name' => 'home_menos_aterro', 'label' => 'Menos envio ao aterro', 'value' => '92%', 'numeric_value' => 92, 'category' => 'home', 'order_index' => 3],
                    ['key_name' => 'home_operacao_organizada', 'label' => 'Operação organizada e documentada', 'value' => '100%', 'numeric_value' => 100, 'category' => 'home', 'order_index' => 4],
                    ['key_name' => 'home_seguranca_juridica', 'label' => 'Segurança jurídica e ambiental', 'value' => '100%', 'numeric_value' => 100, 'category' => 'home', 'order_index' => 5],
                    ['key_name' => 'home_esg_valor', 'label' => 'Valor e reputação para sua marca', 'value' => 'ESG', 'numeric_value' => null, 'category' => 'home', 'order_index' => 6],
                    ['key_name' => 'home_stat_rastreabilidade', 'label' => 'Hero: rastreabilidade', 'value' => '100%', 'numeric_value' => 100, 'category' => 'home', 'order_index' => 7],
                    ['key_name' => 'home_stat_conformidade', 'label' => 'Hero: conformidade', 'value' => 'PNRS', 'numeric_value' => null, 'category' => 'home', 'order_index' => 8],
                    ['key_name' => 'home_stat_documentacao', 'label' => 'Hero: documentação', 'value' => 'MTR + CDF', 'numeric_value' => null, 'category' => 'home', 'order_index' => 9],
                    ['key_name' => 'home_stat_esg', 'label' => 'Hero: aplicado', 'value' => 'ESG', 'numeric_value' => null, 'category' => 'home', 'order_index' => 10],

                    // ESG
                    ['key_name' => 'esg_donut_carbono', 'label' => 'Carbono evitado / Desvio (%)', 'value' => '92%', 'numeric_value' => 92, 'category' => 'esg', 'order_index' => 1],
                    ['key_name' => 'esg_pessoas_impactadas', 'label' => 'Pessoas impactadas', 'value' => '300 Mil', 'numeric_value' => 300000, 'category' => 'esg', 'order_index' => 2],
                    ['key_name' => 'esg_co2_evitado', 'label' => 'CO₂ evitado', 'value' => '190 tCO₂e', 'numeric_value' => 190, 'category' => 'esg', 'order_index' => 3],
                    ['key_name' => 'esg_energia_economizada', 'label' => 'Energia economizada', 'value' => '300 Mil kWh', 'numeric_value' => 300000, 'category' => 'esg', 'order_index' => 4],
                    ['key_name' => 'esg_arvores_preservadas', 'label' => 'Árvores preservadas', 'value' => '1.300', 'numeric_value' => 1300, 'category' => 'esg', 'order_index' => 5],
                    ['key_name' => 'esg_carros_fora', 'label' => 'Carros fora de circulação', 'value' => '120', 'numeric_value' => 120, 'category' => 'esg', 'order_index' => 6],
                    ['key_name' => 'esg_agua_poupada', 'label' => 'Litros de água poupada', 'value' => '7 Mi', 'numeric_value' => 7000000, 'category' => 'esg', 'order_index' => 7],
                ];

                $insertInd = $db->prepare("
                    INSERT INTO site_indicators (key_name, label, value, numeric_value, category, order_index)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                foreach ($defaultIndicators as $ind) {
                    $insertInd->execute([
                        $ind['key_name'],
                        $ind['label'],
                        $ind['value'],
                        $ind['numeric_value'],
                        $ind['category'],
                        $ind['order_index'],
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log("Indicators seeding notice: " . $e->getMessage());
        }
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

        $ip = $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }
        $ip = substr($ip, 0, 45);

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

