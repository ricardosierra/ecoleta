<?php
declare(strict_types=1);
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['root', 'master'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado.']);
    exit;
}

$logosDir = __DIR__ . '/../../logos';
$files = [];

if (is_dir($logosDir)) {
    $dirItems = scandir($logosDir);
    if ($dirItems !== false) {
        foreach ($dirItems as $item) {
            if ($item === '.' || $item === '..') continue;
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'svg', 'webp', 'gif'])) {
                $files[] = [
                    'filename' => $item,
                    'path' => '/logos/' . $item,
                ];
            }
        }
    }
}

echo json_encode([
    'ok' => true,
    'logos' => $files
]);
