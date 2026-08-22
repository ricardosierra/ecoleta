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
            CREATE TABLE IF NOT EXISTS partners (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                src VARCHAR(255) NOT NULL,
                order_index INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_partner_order (order_index),
                INDEX idx_partner_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Seed dos parceiros padrão caso a tabela esteja vazia
        try {
            $countStmt = $db->query("SELECT COUNT(*) AS total FROM partners");
            $totalPartners = $countStmt ? (int)$countStmt->fetchColumn() : 0;
            if ($totalPartners === 0) {
                $defaultPartners = [
                    ['name' => 'Heineken', 'src' => '/logos/heineken.png'],
                    ['name' => 'LIESA', 'src' => '/logos/liesa.png'],
                    ['name' => 'VIBRA', 'src' => '/logos/vibra.png'],
                    ['name' => 'GH Music', 'src' => '/logos/ghmusic.png'],
                    ['name' => 'BrasilCap', 'src' => '/logos/brasilcap.png'],
                    ['name' => 'CEDAE', 'src' => '/logos/cedae.png'],
                    ['name' => 'Rio Carnaval', 'src' => '/logos/rio-carnaval.png'],
                    ['name' => 'Levels', 'src' => '/logos/levels-correct.png'],
                    ['name' => 'Bosque Bar', 'src' => '/logos/bosque-bar.png'],
                    ['name' => 'Ferro & Brasa', 'src' => '/logos/ferro-e-brasa.png'],
                    ['name' => 'WeMake', 'src' => '/logos/wemake.png'],
                    ['name' => 'Virada Sustentável', 'src' => '/logos/virada-sustentavel.png'],
                    ['name' => 'Rio FutSummit 26', 'src' => '/logos/rio-futsummit-26.png'],
                    ['name' => 'Sacadura 154', 'src' => '/logos/sacadura-154.png'],
                    ['name' => 'NMLSS', 'src' => '/logos/nmlss-correct.png'],
                    ['name' => 'Subsea 7', 'src' => '/logos/subsea7.png'],
                    ['name' => 'Café Preto Tattoo', 'src' => '/logos/cafe-preto-tattoo-correct.png'],
                    ['name' => 'FuraTodo', 'src' => '/logos/fura-toblu.png'],
                    ['name' => 'Salgueiro', 'src' => '/logos/salgueiro.png'],
                    ['name' => 'Cidaddess', 'src' => '/logos/cidaddess.png'],
                    ['name' => 'Silimed', 'src' => '/logos/silimed.png'],
                    ['name' => 'Maltas', 'src' => '/logos/maltas.png'],
                    ['name' => 'Grupo Onda', 'src' => '/logos/grupo-onda.png'],
                ];
                $insertPartner = $db->prepare("INSERT INTO partners (name, src, order_index, is_active) VALUES (?, ?, ?, 1)");
                foreach ($defaultPartners as $idx => $p) {
                    $insertPartner->execute([$p['name'], $p['src'], $idx]);
                }
            }
        } catch (\Throwable $e) {
            error_log("Partner seeding notice: " . $e->getMessage());
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

