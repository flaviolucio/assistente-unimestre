<?php

declare(strict_types=1);

namespace App\Services\AI\Http;

/**
 * Transporte HTTP via cURL para providers de IA.
 *
 * - O verificação TLS fica SEMPRE habilitada (jamais CURLOPT_SSL_VERIFYPEER false).
 * - A credencial (Authorization) chega em $headers e nunca é persistida/registrada.
 * - Timeouts de conexão e total são aplicados por chamada.
 */
final class CurlTransport implements HttpTransportInterface
{
    public function postJson(
        string $url,
        array $headers,
        array $payload,
        int $connectTimeout,
        int $totalTimeout
    ): array {
        $started = microtime(true);

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            return [
                'status' => 0,
                'body' => '',
                'error' => 'Falha ao serializar o payload JSON.',
                'error_no' => 0,
                'headers' => [],
                'latency_ms' => 0.0,
            ];
        }

        $headerLines = [];

        foreach ($headers as $key => $value) {
            $headerLines[] = $key . ': ' . $value;
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT => max(1, $connectTimeout),
            CURLOPT_TIMEOUT => max(1, $totalTimeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => (string) ($headers['User-Agent'] ?? 'assistente-unimestre/1.0'),
        ];

        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);

        if ($raw === false) {
            $error = (string) curl_error($ch);
            $errorNo = (int) curl_errno($ch);
            curl_close($ch);

            return [
                'status' => 0,
                'body' => '',
                'error' => $error,
                'error_no' => $errorNo,
                'headers' => [],
                'latency_ms' => round((microtime(true) - $started) * 1000, 2),
            ];
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $headerSize = strpos($raw, "\r\n\r\n");
        $headerBlock = $headerSize === false ? '' : substr($raw, 0, $headerSize);
        $body = $headerSize === false ? $raw : substr($raw, $headerSize + 4);

        $responseHeaders = [];

        foreach (explode("\r\n", $headerBlock) as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($key))] = trim($value);
            }
        }

        return [
            'status' => $status,
            'body' => (string) $body,
            'error' => '',
            'error_no' => 0,
            'headers' => $responseHeaders,
            'latency_ms' => round((microtime(true) - $started) * 1000, 2),
        ];
    }
}