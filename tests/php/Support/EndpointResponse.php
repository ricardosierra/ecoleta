<?php
declare(strict_types=1);

/** Resultado de uma chamada a um endpoint de public/api/. */
final class EndpointResponse
{
    /**
     * @param array<string,mixed> $session a sessão como o endpoint a deixou —
     *                                     é assim que os testes veem que o login
     *                                     gravou user_id/role, ou que uma recusa
     *                                     não gravou nada.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        /** O que o endpoint escreveu em error_log() durante a chamada. */
        public readonly string $errorLog,
        /** Erro fatal do PHP, quando houve. */
        public readonly ?string $fatal,
        public readonly array $session
    ) {
    }

    /** Corpo decodificado. Falha alto em vez de devolver null silencioso. */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf(
                "Resposta não é JSON (status %d).\nCorpo: %s\nerror_log: %s\nFatal: %s",
                $this->status,
                $this->body,
                $this->errorLog,
                $this->fatal ?? '(nenhum)'
            ));
        }

        return $decoded;
    }

    public function error(): ?string
    {
        $payload = $this->json();

        return isset($payload['error']) ? (string) $payload['error'] : null;
    }

    public function logged(string $needle): bool
    {
        return str_contains($this->errorLog, $needle);
    }
}
