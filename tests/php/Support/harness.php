<?php
declare(strict_types=1);

/**
 * Prólogo injetado com `-d auto_prepend_file` no processo que roda um endpoint
 * de public/api/. Corre ANTES do script sob teste e monta a requisição falsa.
 *
 * Não é carregado pela suíte: quem o executa é o `php` filho disparado por
 * tests/php/Support/Endpoint.php.
 *
 * Responsabilidades, nesta ordem:
 *  1. reconstruir $_SERVER, $_GET e o corpo lido de php://input;
 *  2. abrir a sessão e semeá-la — como a sessão já fica ativa, o
 *     startSecureSession() do endpoint devolve na primeira linha e o papel que
 *     plantamos aqui é o que a regra vai ler;
 *  3. capturar status, corpo, sessão final e error_log em um arquivo JSON.
 *
 * Limitação conhecida: no SAPI de linha de comando `header()` não faz nada e
 * `headers_list()` vem vazio, então cabeçalhos (Retry-After, Cache-Control)
 * não são observáveis daqui. Asserção de cabeçalho fica fora da suíte — de
 * propósito, para não existir teste que finge verificar.
 */

/**
 * O SAPI de linha de comando não preenche php://input: `file_get_contents` nele
 * devolve string vazia, e todo endpoint que lê o corpo veria uma requisição
 * vazia. Este wrapper substitui o esquema `php://` para servir o corpo do teste
 * em `php://input` e delegar qualquer outro caminho ao wrapper original.
 */
final class EcoletaTestPhpStream
{
    public static string $input = '';

    /** @var resource|null */
    public $context;

    private int $position = 0;

    /** @var resource|null Fluxo real, quando o caminho não é php://input. */
    private $delegate = null;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (strcasecmp($path, 'php://input') === 0) {
            $this->position = 0;

            return true;
        }

        // Qualquer outro php:// (stdout, stderr, memory, temp, filter/...) é
        // aberto pelo wrapper de verdade, com o nosso desregistrado no meio.
        stream_wrapper_restore('php');
        $this->delegate = $this->context !== null
            ? @fopen($path, $mode, ($options & STREAM_USE_PATH) !== 0, $this->context)
            : @fopen($path, $mode, ($options & STREAM_USE_PATH) !== 0);
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', self::class);

        return $this->delegate !== false && $this->delegate !== null;
    }

    public function stream_read(int $count): string
    {
        if ($this->delegate !== null) {
            $read = fread($this->delegate, $count);

            return $read === false ? '' : $read;
        }

        $chunk = substr(self::$input, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_write(string $data): int
    {
        if ($this->delegate !== null) {
            $written = fwrite($this->delegate, $data);

            return $written === false ? 0 : $written;
        }

        return 0;
    }

    public function stream_eof(): bool
    {
        return $this->delegate !== null
            ? feof($this->delegate)
            : $this->position >= strlen(self::$input);
    }

    public function stream_tell(): int
    {
        return $this->delegate !== null ? (int) ftell($this->delegate) : $this->position;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        if ($this->delegate !== null) {
            return fseek($this->delegate, $offset, $whence) === 0;
        }

        $target = match ($whence) {
            SEEK_CUR => $this->position + $offset,
            SEEK_END => strlen(self::$input) + $offset,
            default => $offset,
        };

        if ($target < 0) {
            return false;
        }

        $this->position = $target;

        return true;
    }

    public function stream_stat(): array
    {
        if ($this->delegate !== null) {
            $stat = fstat($this->delegate);

            return $stat === false ? [] : $stat;
        }

        return ['size' => strlen(self::$input)];
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        if ($this->delegate !== null) {
            fclose($this->delegate);
            $this->delegate = null;
        }
    }

    public function url_stat(string $path, int $flags)
    {
        return false;
    }
}

$contextFile = getenv('ECOLETA_TEST_CONTEXT');
if ($contextFile === false || !is_file($contextFile)) {
    fwrite(STDERR, "harness: ECOLETA_TEST_CONTEXT ausente ou ilegível.\n");
    exit(70);
}

$context = json_decode((string) file_get_contents($contextFile), true);
if (!is_array($context)) {
    fwrite(STDERR, "harness: contexto de teste inválido.\n");
    exit(70);
}

$_SERVER = array_merge($_SERVER, $context['server']);
$_GET = $context['query'];
$_POST = [];
$_REQUEST = $_GET;

EcoletaTestPhpStream::$input = (string) $context['body'];
stream_wrapper_unregister('php');
stream_wrapper_register('php', EcoletaTestPhpStream::class);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $context['error_log_file']);
error_reporting(E_ALL);

// Sessão em diretório próprio da chamada: um teste nunca enxerga a sessão de
// outro, mesmo com a suíte rodando em paralelo.
ini_set('session.save_path', $context['session_dir']);
ini_set('session.use_cookies', '0');
ini_set('session.use_strict_mode', '0');
session_id($context['session_id']);
session_start();

foreach ($context['session'] as $key => $value) {
    $_SESSION[$key] = $value;
}

ob_start();

register_shutdown_function(static function () use ($context): void {
    $body = ob_get_clean();
    if ($body === false) {
        $body = '';
    }

    $fatal = null;
    $last = error_get_last();
    if ($last !== null && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $fatal = sprintf('%s em %s:%d', $last['message'], $last['file'], $last['line']);
    }

    $status = http_response_code();

    // A gravação usa o wrapper de arquivo, não o php:// que trocamos acima.
    file_put_contents($context['output_file'], json_encode([
        'status' => is_int($status) ? $status : 200,
        'body' => $body,
        'fatal' => $fatal,
        'session' => $_SESSION ?? [],
    ], JSON_UNESCAPED_UNICODE));
});
