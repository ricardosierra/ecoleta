<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../authz.php';
require_once __DIR__ . '/logo_lib.php';

startSecureSession();
apiRequireCsrfToken();
apiSendJsonHeaders();

$db = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->query("SELECT id, name, logo_url, is_active FROM site_clients ORDER BY name ASC");
    $companies = $stmt->fetchAll();
    echo json_encode(['ok' => true, 'companies' => $companies]);
    exit;
}

$operator = apiRequireAdmin();
$operatorId = (int) $operator['id'];
$operatorLogin = (string) $operator['login'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // create com arquivo chega como multipart/form-data ($_POST + $_FILES);
    // as demais ações seguem no corpo JSON de sempre.
    $isMultipart = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
    $body = $isMultipart
        ? $_POST
        : (json_decode((string) file_get_contents('php://input'), true) ?? []);

    $action = (string) ($body['action'] ?? '');

    if ($action === 'create') {
        $name = trim((string) ($body['name'] ?? ''));
        $logoUrl = trim((string) ($body['logo_url'] ?? ''));
        $file = $isMultipart ? ($_FILES['logo'] ?? null) : null;
        $hasFile = is_array($file) && (
            is_array($file['error'] ?? null)
                ? !empty($file['error'])
                : (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        );

        if ($name === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Nome é obrigatório.']);
            exit;
        }

        if (!$hasFile && $logoUrl === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Envie a imagem da logo ou informe um caminho.']);
            exit;
        }

        // A UNIQUE em name (migration 013) já barraria no INSERT; conferir
        // antes devolve uma mensagem clara em vez de um erro de banco.
        $stmt = $db->prepare("SELECT id FROM site_clients WHERE name = ?");
        $stmt->execute([$name]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'Já existe uma empresa com esse nome.']);
            exit;
        }

        if ($hasFile) {
            $uploadError = ecoletaLogoValidateUpload($file);
            $tmpName = is_array($file) && is_string($file['tmp_name'] ?? null) ? (string) $file['tmp_name'] : '';
            if ($uploadError === null && ($tmpName === '' || !ecoletaLogoIsUploadedFile($tmpName))) {
                $uploadError = 'Falha no envio da imagem. Tente de novo.';
            }
            if ($uploadError !== null) {
                http_response_code(400);
                echo json_encode(['error' => $uploadError]);
                exit;
            }

            $processed = ecoletaLogoProcess($tmpName, ecoletaLogoUploadsDir(), $name);
            if (!$processed['ok']) {
                http_response_code(422);
                echo json_encode(['error' => $processed['error']]);
                exit;
            }

            $logoUrl = ECOLETA_LOGO_PUBLIC_PREFIX . $processed['filename'];
        } elseif (!str_starts_with($logoUrl, '/') && !preg_match('#^https?://#i', $logoUrl)) {
            http_response_code(400);
            echo json_encode(['error' => 'Caminho da logo inválido — use /logos/... ou uma URL completa.']);
            exit;
        }

        try {
            $stmt = $db->prepare("INSERT INTO site_clients (name, logo_url, is_active) VALUES (?, ?, 1)");
            $stmt->execute([$name, $logoUrl]);
        } catch (PDOException $e) {
            // Corrida entre a conferência acima e o INSERT: outra requisição
            // criou o mesmo nome no meio do caminho.
            ecoletaLogoDeleteByUrl($logoUrl);
            if ($e->getCode() === '23000') {
                http_response_code(409);
                echo json_encode(['error' => 'Já existe uma empresa com esse nome.']);
                exit;
            }

            http_response_code(500);
            echo json_encode(['error' => 'Erro interno ao cadastrar empresa.']);
            exit;
        }

        $id = (int) $db->lastInsertId();

        logActivity(
            $db,
            null,
            'site_client_create',
            "Empresa parceira '{$name}' cadastrada por {$operatorLogin}",
            $operatorId,
            $operatorLogin
        );

        echo json_encode(['ok' => true, 'id' => $id, 'logo_url' => $logoUrl]);
        exit;
    }

    if ($action === 'toggle_active') {
        $id = (int) ($body['id'] ?? 0);
        $isActive = (int) ($body['is_active'] ?? 1) === 1 ? 1 : 0;

        $stmt = $db->prepare("SELECT name FROM site_clients WHERE id = ?");
        $stmt->execute([$id]);
        $company = $stmt->fetch();
        if (!$company) {
            http_response_code(404);
            echo json_encode(['error' => 'Empresa não encontrada.']);
            exit;
        }

        $stmt = $db->prepare("UPDATE site_clients SET is_active = ? WHERE id = ?");
        $stmt->execute([$isActive, $id]);

        logActivity(
            $db,
            null,
            'site_client_toggle',
            sprintf(
                "Empresa parceira '%s' %s por %s",
                $company['name'],
                $isActive === 1 ? 'ativada' : 'desativada',
                $operatorLogin
            ),
            $operatorId,
            $operatorLogin
        );

        echo json_encode(['ok' => true, 'id' => $id, 'is_active' => $isActive]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int) ($body['id'] ?? 0);

        $stmt = $db->prepare("SELECT name, logo_url FROM site_clients WHERE id = ?");
        $stmt->execute([$id]);
        $company = $stmt->fetch();
        if (!$company) {
            http_response_code(404);
            echo json_encode(['error' => 'Empresa não encontrada.']);
            exit;
        }

        $stmt = $db->prepare("DELETE FROM site_clients WHERE id = ?");
        $stmt->execute([$id]);

        ecoletaLogoDeleteByUrl((string) $company['logo_url']);

        logActivity(
            $db,
            null,
            'site_client_delete',
            "Empresa parceira '{$company['name']}' excluída por {$operatorLogin}",
            $operatorId,
            $operatorLogin
        );

        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Ação inválida.']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido.']);
