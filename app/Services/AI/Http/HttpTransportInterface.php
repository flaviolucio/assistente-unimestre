<?php

declare(strict_types=1);

namespace App\Services\AI\Http;

/**
 * Abstração do transporte HTTP para a camada de IA. Permite testes sem
 * internet por meio de implementações fake em memória.
 */
interface HttpTransportInterface
{
    /**
     * Realiza uma requisição POST com corpo JSON.
     *
     * Retorno:
     *  - status: código HTTP (0 quando houve falha de transporte)
     *  - body: corpo da resposta (sem payload sensível)
     *  - error: mensagem de erro de transporte (vazia em sucesso)
     *  - error_no: código CURLOPT semântico (0 em sucesso)
     *  - headers: cabeçalhos da resposta em minúsculas
     *  - latency_ms: duração da chamada
     *
     * @param array<string, string> $headers
     * @param array<mixed>          $payload
     *
     * @return array{status: int, body: string, error: string, error_no: int, headers: array<string,string>, latency_ms: float}
     */
    public function postJson(
        string $url,
        array $headers,
        array $payload,
        int $connectTimeout,
        int $totalTimeout
    ): array;
}