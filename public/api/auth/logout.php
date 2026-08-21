<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
session_start();

if (isset($_SESSION['user_id'])) {
    $db = getDbConnection();
    logActivity(
        $db,
        (int)$_SESSION['user_id'],
        'logout',
        'Logout efetuado com sucesso',
        (int)$_SESSION['user_id'],
        $_SESSION['login'] ?? 'usuario',
        $_SESSION['login'] ?? null
    );
}

session_destroy();
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true]);
