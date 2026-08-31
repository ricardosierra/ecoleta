<?php
require_once __DIR__ . '/db.php';
try {
    $db = getDbConnection(false);
    $db->exec("CREATE TABLE IF NOT EXISTS site_clients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        logo_url VARCHAR(255) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $db->exec("CREATE TABLE IF NOT EXISTS site_indicators (
        id INT AUTO_INCREMENT PRIMARY KEY,
        indicator_key VARCHAR(50) NOT NULL UNIQUE,
        value VARCHAR(50) NOT NULL,
        label VARCHAR(100) NOT NULL,
        symbol_type ENUM('text', 'icon') NOT NULL DEFAULT 'text',
        symbol_value VARCHAR(100) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $db->exec("CREATE TABLE IF NOT EXISTS site_indicator_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        indicator_key VARCHAR(50) NOT NULL,
        old_value VARCHAR(50) NOT NULL,
        new_value VARCHAR(50) NOT NULL,
        changed_by_id INT NULL,
        changed_by_login VARCHAR(50) NULL,
        ip_address VARCHAR(45) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_history_key (indicator_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $db->exec("INSERT IGNORE INTO site_indicators (indicator_key, value, label, symbol_type, symbol_value) VALUES
    ('custo', '↓R$', 'Redução de custo operacional', 'text', '↓R$'),
    ('aterro', '92%', 'Menos envio ao aterro', 'text', '92%'),
    ('docs', 'CheckIcon', 'Operação organizada e documentada', 'icon', 'CheckIcon'),
    ('seguranca', 'ScaleIcon', 'Segurança jurídica e ambiental', 'icon', 'ScaleIcon'),
    ('esg', 'ESG', 'Valor e reputação para sua marca', 'text', 'ESG');");
    $db->exec("INSERT IGNORE INTO site_clients (name, logo_url, is_active) VALUES
    ('Heineken', '/logos/heineken.png', 1),
    ('LIESA', '/logos/liesa.png', 1),
    ('VIBRA', '/logos/vibra.png', 1),
    ('GH Music', '/logos/ghmusic.png', 1),
    ('BrasilCap', '/logos/brasilcap.png', 1),
    ('CEDAE', '/logos/cedae.png', 1),
    ('Rio Carnaval', '/logos/rio-carnaval.png', 1),
    ('Levels', '/logos/levels-correct.png', 1),
    ('Bosque Bar', '/logos/bosque-bar.png', 1),
    ('Ferro & Brasa', '/logos/ferro-e-brasa.png', 1),
    ('WeMake', '/logos/wemake.png', 1),
    ('Virada Sustentável', '/logos/virada-sustentavel.png', 1),
    ('Rio FutSummit 26', '/logos/rio-futsummit-26.png', 1),
    ('Sacadura 154', '/logos/sacadura-154.png', 1),
    ('NMLSS', '/logos/nmlss-correct.png', 1),
    ('Subsea 7', '/logos/subsea7.png', 1),
    ('Café Preto Tattoo', '/logos/cafe-preto-tattoo-correct.png', 1),
    ('FuraTodo', '/logos/fura-toblu.png', 1),
    ('Salgueiro', '/logos/salgueiro.png', 1),
    ('Cidaddess', '/logos/cidaddess.png', 1),
    ('Silimed', '/logos/silimed.png', 1),
    ('Maltas', '/logos/maltas.png', 1),
    ('Grupo Onda', '/logos/grupo-onda.png', 1);");

    echo "OK";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
