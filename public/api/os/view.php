<?php
declare(strict_types=1);

/**
 * Página pública de uma Ordem de Serviço — o destino do link encaminhado por
 * e-mail ou WhatsApp.
 *
 * Quem recebe a OS é o cliente, que não tem conta no dashboard: a autorização
 * aqui é a posse do `share_token` da linha (migration 014), comparado com
 * `hash_equals`. Id sem token, token errado e OS inexistente devolvem a MESMA
 * página 404 — a resposta não confirma se a OS existe.
 *
 * Devolve HTML, não JSON: o destinatário abre isto no navegador do celular.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/os_lib.php';

/** 404 humano, idêntico para todos os motivos de recusa. */
function osViewNotFound(): void
{
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
    }

    http_response_code(404);
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<title>Ordem de Serviço não encontrada</title></head>'
        . '<body style="margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center;'
        . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;background:#ECF5FB;color:#242424;">'
        . '<p style="max-width:420px;text-align:center;padding:24px;">Este link de Ordem de Serviço não é válido ou foi substituído. '
        . 'Peça um novo à equipe da Ecoleva.</p></body></html>';
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    osViewNotFound();
}

$id = (int) ($_GET['id'] ?? 0);
$token = (string) ($_GET['t'] ?? '');

// Token fora do formato nem chega ao banco.
if ($id <= 0 || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
    osViewNotFound();
}

$db = getDbConnection();
$os = osFindShared($db, $id, $token);

if ($os === null) {
    osViewNotFound();
}

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
}

echo osViewPageHtml($os, osBaseUrl());
