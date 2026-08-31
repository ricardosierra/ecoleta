<?php
declare(strict_types=1);

/**
 * Contrato de schema entre a aplicação e o banco.
 *
 * O DDL não mora mais aqui: quem cria e altera tabelas é db/migrate.php, rodado
 * por SSH/CLI antes do deploy dos arquivos. O que sobra no caminho do request é
 * uma pergunta barata — "o banco já recebeu as migrations que este código
 * exige?" — e uma resposta 503 quando não recebeu.
 *
 * Falhar rápido é de propósito. A alternativa anterior, tentar se auto-consertar
 * com CREATE TABLE/ALTER TABLE a cada requisição, cobrava metadata lock em toda
 * chamada e exigia que o usuário MySQL da aplicação tivesse permissão de DDL.
 */

require_once __DIR__ . '/security.php';

/**
 * Versão de schema que ESTE código exige — o maior número de migration em
 * db/migrations/.
 *
 * Ao adicionar uma migration, suba este número junto. db/migrate.php compara os
 * dois no fim de cada execução e recusa passar se divergirem, então o valor não
 * fica para trás em silêncio.
 */
const ECOLETA_SCHEMA_VERSION = 10;

/** Nome da tabela-registro escrita por db/migrate.php. */
const ECOLETA_SCHEMA_TABLE = 'schema_migrations';

/**
 * Maior versão já aplicada.
 *
 *  - int  — versão registrada (0 quando a tabela existe mas está vazia)
 *  - null — a tabela `schema_migrations` não existe: banco nunca migrado
 */
function apiAppliedSchemaVersion(PDO $db): ?int
{
    try {
        $stmt = $db->query('SELECT MAX(version) AS version FROM ' . ECOLETA_SCHEMA_TABLE);
        $row = $stmt ? $stmt->fetch() : null;

        if (!is_array($row) || $row['version'] === null) {
            return 0;
        }

        return (int) $row['version'];
    } catch (PDOException $e) {
        // 42S02 = base table not found. Qualquer outro erro (permissão, conexão
        // caída) é problema diferente e sobe para quem chamou.
        if ($e->getCode() === '42S02') {
            return null;
        }

        throw $e;
    }
}

/**
 * Encerra a requisição com 503. O motivo detalhado fica só no log do servidor —
 * a resposta não conta ao cliente em que versão o banco está.
 */
function apiSchemaUnavailable(string $reason): void
{
    error_log(sprintf(
        'Schema do banco fora de dia (%s). Aplique as migrations com `php db/migrate.php migrate` a partir de um checkout do repositório, com o usuário MySQL de DDL.',
        $reason
    ));

    apiJsonResponse(
        503,
        [
            'error' => 'Serviço em manutenção. Tente novamente em alguns minutos.',
            'code' => 'schema_out_of_date',
        ],
        ['Retry-After: 60']
    );
}

/**
 * Verifica uma única vez por requisição se o banco está em dia.
 *
 * Banco ATRÁS do código → 503: as queries dos endpoints usariam colunas que
 * ainda não existem, e falhar aqui dá uma mensagem clara em vez de um erro de
 * SQL solto no log.
 *
 * Banco À FRENTE do código → segue servindo, só registra no log. É o estado
 * normal e esperado durante um deploy: as migrations sobem primeiro, os
 * arquivos depois. Derrubar o site nessa janela seria o oposto do objetivo.
 */
function apiRequireCurrentSchema(PDO $db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    try {
        $applied = apiAppliedSchemaVersion($db);
    } catch (\Throwable $e) {
        apiSchemaUnavailable('não foi possível ler ' . ECOLETA_SCHEMA_TABLE . ': ' . $e->getMessage());

        return; // inalcançável: apiSchemaUnavailable encerra a requisição
    }

    if ($applied === null) {
        apiSchemaUnavailable('a tabela ' . ECOLETA_SCHEMA_TABLE . ' não existe — nenhuma migration foi aplicada neste banco');

        return;
    }

    if ($applied < ECOLETA_SCHEMA_VERSION) {
        apiSchemaUnavailable(sprintf(
            'banco na versão %d, código exige %d — faltam %d migration(s)',
            $applied,
            ECOLETA_SCHEMA_VERSION,
            ECOLETA_SCHEMA_VERSION - $applied
        ));

        return;
    }

    if ($applied > ECOLETA_SCHEMA_VERSION) {
        error_log(sprintf(
            'Banco na versão %d, à frente do código (%d). Normal entre a aplicação das migrations e o upload dos arquivos; se persistir, o deploy dos arquivos ficou pela metade.',
            $applied,
            ECOLETA_SCHEMA_VERSION
        ));
    }

    $checked = true;
}
