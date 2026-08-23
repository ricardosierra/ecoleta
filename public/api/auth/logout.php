<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

startSecureSession();
// Logout passou a ser POST com token: sem isso, um <img src="...logout.php">
// em qualquer página derrubava a sessão do usuário.
apiRequireCsrfToken();

apiSendJsonHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiJsonResponse(405, ['error' => 'Método não permitido.']);
}

if (isset($_SESSION['user_id'])) {
    $db = getDbConnection();
    logActivity(
        $db,
        (int) $_SESSION['user_id'],
        'logout',
        'Logout efetuado com sucesso',
        (int) $_SESSION['user_id'],
        $_SESSION['login'] ?? 'usuario',
        $_SESSION['login'] ?? null
    );
}

apiDestroySession();

apiJsonResponse(200, ['ok' => true]);
