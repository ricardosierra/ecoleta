<?php
declare(strict_types=1);

/**
 * Chama um endpoint de public/api/ como o servidor chamaria: um processo PHP
 * novo, executando o arquivo de verdade, do começo ao fim.
 *
 * A alternativa seria `require` do endpoint dentro do teste, mas esses scripts
 * chamam `exit` e definem constantes no escopo global — o primeiro teste
 * derrubaria a suíte inteira. Um processo por chamada também dá isolamento de
 * sessão e de conexão de graça.
 *
 * O banco é apontado por DB_DSN (ver apiDatabaseDsn() em public/api/db.php), a
 * requisição é montada por tests/php/Support/harness.php e o resultado volta em
 * um arquivo JSON.
 */
final class Endpoint
{
    /**
     * @param string $script  caminho relativo a public/api/, ex.: 'users/edit.php'
     * @param array{
     *     method?: string,
     *     body?: array|string|null,
     *     query?: array<string,string|int>,
     *     session?: array<string,mixed>,
     *     csrf?: string|bool,
     *     dsn?: string|null,
     *     remote_addr?: string,
     *     server?: array<string,string>
     * } $options
     */
    public static function call(string $script, array $options = []): EndpointResponse
    {
        $path = ECOLETA_API_DIR . '/' . ltrim($script, '/');
        if (!is_file($path)) {
            throw new RuntimeException("Endpoint inexistente: {$path}");
        }

        $method = strtoupper($options['method'] ?? 'POST');
        $body = $options['body'] ?? null;
        $rawBody = is_array($body) ? (string) json_encode($body, JSON_UNESCAPED_UNICODE) : (string) ($body ?? '');

        // Token CSRF: por padrão a sessão traz um e a requisição manda o mesmo —
        // o caso normal. `csrf => false` simula quem não mandou o cabeçalho;
        // uma string simula quem mandou o token errado.
        $sessionToken = str_repeat('a', 64);
        $csrf = $options['csrf'] ?? true;
        $sentToken = $csrf === true ? $sessionToken : (is_string($csrf) ? $csrf : null);

        $session = $options['session'] ?? [];
        $session['csrf_token'] = $sessionToken;
        $session['last_activity'] = time();

        $server = [
            'REQUEST_METHOD' => $method,
            'SCRIPT_NAME' => '/api/' . ltrim($script, '/'),
            'SCRIPT_FILENAME' => $path,
            'REQUEST_URI' => '/api/' . ltrim($script, '/'),
            'REMOTE_ADDR' => $options['remote_addr'] ?? '203.0.113.10',
            'HTTP_USER_AGENT' => 'EcoletaTestes/1.0',
            'HTTP_HOST' => 'localhost',
            'SERVER_PORT' => '80',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => (string) strlen($rawBody),
        ];

        if ($sentToken !== null) {
            $server['HTTP_X_CSRF_TOKEN'] = $sentToken;
        }

        $server = array_merge($server, $options['server'] ?? []);

        $workDir = self::makeTempDir();
        $context = [
            'server' => $server,
            'query' => array_map('strval', $options['query'] ?? []),
            'session' => $session,
            'body' => $rawBody,
            'session_id' => 'ecoletatest' . bin2hex(random_bytes(8)),
            'session_dir' => $workDir . '/sessions',
            'error_log_file' => $workDir . '/error.log',
            'output_file' => $workDir . '/response.json',
        ];
        mkdir($context['session_dir'], 0700, true);
        touch($context['error_log_file']);

        $contextFile = $workDir . '/context.json';
        file_put_contents($contextFile, json_encode($context, JSON_UNESCAPED_UNICODE));

        $env = [
            'ECOLETA_TEST_CONTEXT' => $contextFile,
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        ];
        if (array_key_exists('dsn', $options)) {
            if ($options['dsn'] !== null) {
                $env['DB_DSN'] = $options['dsn'];
            }
        } else {
            throw new InvalidArgumentException('Passe dsn: explicitamente (use null para não definir DB_DSN).');
        }

        self::run($path, $env, $rawBody, $workDir);

        $raw = file_get_contents($context['output_file']);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($decoded)) {
            $stderr = @file_get_contents($workDir . '/stderr.log') ?: '';
            $log = @file_get_contents($context['error_log_file']) ?: '';
            self::removeTree($workDir);

            throw new RuntimeException(
                "O endpoint {$script} não produziu resposta.\nstderr: {$stderr}\nerror_log: {$log}"
            );
        }

        $log = (string) (@file_get_contents($context['error_log_file']) ?: '');
        self::removeTree($workDir);

        return new EndpointResponse(
            (int) $decoded['status'],
            (string) $decoded['body'],
            $log,
            $decoded['fatal'] === null ? null : (string) $decoded['fatal'],
            is_array($decoded['session'] ?? null) ? $decoded['session'] : []
        );
    }

    private static function run(string $path, array $env, string $stdin, string $workDir): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $workDir . '/stdout.log', 'w'],
            2 => ['file', $workDir . '/stderr.log', 'w'],
        ];

        $command = [
            PHP_BINARY,
            '-d', 'auto_prepend_file=' . __DIR__ . '/harness.php',
            '-d', 'display_errors=0',
            $path,
        ];

        $process = proc_open($command, $descriptors, $pipes, ECOLETA_ROOT, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('Não consegui iniciar o processo PHP do endpoint.');
        }

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        proc_close($process);
    }

    private static function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/ecoleta_endpoint_' . bin2hex(random_bytes(8));
        mkdir($dir, 0700, true);

        return $dir;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
