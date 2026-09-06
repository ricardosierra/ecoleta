<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once __DIR__ . '/api/env.php';
$db = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME),
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    version INT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    checksum VARCHAR(64) NOT NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    statements INT NOT NULL DEFAULT 0,
    execution_ms INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$stmt = $db->query('SELECT MAX(version) AS v FROM schema_migrations');
$maxVersion = (int) ($stmt->fetch()['v'] ?? 0);
echo "Current version: $maxVersion\n";

$migrations = [];
foreach (glob(__DIR__ . '/db/migrations/*.sql') as $file) {
    if (preg_match('/^0*(\d+)_(.+)\.sql$/', basename($file), $m)) {
        $migrations[(int) $m[1]] = $file;
    }
}
ksort($migrations);

foreach ($migrations as $v => $file) {
    if ($v > $maxVersion) {
        echo "Applying $v...\n";
        $sql = file_get_contents($file);
        $db->exec($sql);
        $checksum = hash('sha256', str_replace("\r\n", "\n", $sql));
        $stmt = $db->prepare('INSERT INTO schema_migrations (version, filename, checksum) VALUES (?, ?, ?)');
        $stmt->execute([$v, basename($file), $checksum]);
        echo "Applied $v.\n";
    }
}
echo "Done.\n";
