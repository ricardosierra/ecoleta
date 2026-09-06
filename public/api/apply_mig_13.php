<?php
declare(strict_types=1);

// Legacy maintenance script: never run DDL or delete data from an HTTP request.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';
$db = getDbConnection(false);

try {
    $db->exec('DELETE sc FROM site_clients sc
    INNER JOIN site_clients keeper
            ON keeper.name = sc.name
           AND keeper.id < sc.id');

    $db->exec('ALTER TABLE site_clients ADD UNIQUE KEY uq_site_clients_name (name)');

    $stmt = $db->prepare('INSERT INTO schema_migrations (version, filename, checksum, statements, execution_ms) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([13, '013_dedup_site_clients.sql', '805089ccfd2cbd2f35694f91f226bf20410a1e525a7bc5f80f26ea6ee868f96f', 2, 10]);

    echo "Migration 13 applied successfully!";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate key name') !== false || strpos($e->getMessage(), 'Duplicate entry') !== false) {
        echo "Maybe already applied or unique error: " . $e->getMessage();
    } else {
        echo "Error: " . $e->getMessage();
    }
}
