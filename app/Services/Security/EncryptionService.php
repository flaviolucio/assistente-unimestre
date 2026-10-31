<?php

declare(strict_types=1);

namespace App\Services\Security;

use RuntimeException;

/**
 * Criptografia de credenciais de providers com OpenSSL AES-256-GCM.
 *
 * O ambiente (PHP 8.0 / XAMPP) nao possui ext-sodium, portanto a opcao
 * segura disponivel e o modo GCM do OpenSSL (autenticado). O payload
 * gerado segue o formato versionado "v1:<base64(nonce|ciphertext|tag)>",
 * permitindo futura rotacao de implementacao/algoritmo sem invalidar
 * dados antigos.
 */
final class EncryptionService
{
    public const VERSION = 'v1';
    public const CIPHER = 'aes-256-gcm';
    private const NONCE_LENGTH = 12;
    private const TAG_LENGTH = 16;

    private string $configuredKey;

    public function __construct(?string $configuredKey = null)
    {
        $this->configuredKey = trim((string) ($configuredKey ?? config('ai.encryption_key', '')));
    }

    /**
     * A chave mestre (APP_ENCRYPTION_KEY) esta presente e com entropia adequada?
     */
    public function isConfigured(): bool
    {
        return $this->normalizeKey($this->configuredKey) !== null;
    }

    public function encrypt(string $plaintext): string
    {
        $key = $this->requireKey();

        $nonce = random_bytes(self::NONCE_LENGTH);
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Falha ao criptografar o valor com OpenSSL AES-256-GCM.');
        }

        $payload = base64_encode($nonce . $ciphertext . $tag);

        return self::VERSION . ':' . $payload;
    }

    /**
     * Decriptografa um payload "v1:...". Falha de forma segura (nunca
     * retorna texto plano) para chaves trocadas ou dados adulterados.
     */
    public function decrypt(string $payload): string
    {
        $key = $this->requireKey();
        $suffix = substr($payload, 0, strlen(self::VERSION) + 1);

        if ($suffix !== self::VERSION . ':') {
            throw new RuntimeException('Formato de payload criptografado inválido.');
        }

        $raw = base64_decode(substr($payload, strlen(self::VERSION) + 1), true);

        if ($raw === false || strlen($raw) < self::NONCE_LENGTH + self::TAG_LENGTH + 1) {
            throw new RuntimeException('Payload criptografado corrompido.');
        }

        $nonce = substr($raw, 0, self::NONCE_LENGTH);
        $tag = substr($raw, -self::TAG_LENGTH);
        $ciphertext = substr($raw, self::NONCE_LENGTH, -self::TAG_LENGTH);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($plaintext === false) {
            throw new RuntimeException(
                'Falha ao descriptografar a credencial (APP_ENCRYPTION_KEY alterada ou dado adulterado).'
            );
        }

        return (string) $plaintext;
    }

    private function requireKey(): string
    {
        $key = $this->normalizeKey($this->configuredKey);

        if ($key === null) {
            throw new RuntimeException(
                'APP_ENCRYPTION_KEY não configurada. Defina a chave no arquivo .env antes de salvar ou ler credenciais.'
            );
        }

        return $key;
    }

    /**
     * Normaliza/certifica a chave. Sempre retorna 32 bytes (AES-256).
     * Formatos aceitos: 64 hex ou string bruta com pelo menos 32 bytes.
     */
    private function normalizeKey(string $key): ?string
    {
        $key = trim($key);

        if ($key === '') {
            return null;
        }

        if (preg_match('/^[0-9a-fA-F]{64}$/', $key) === 1) {
            return hex2bin($key);
        }

        if (strlen($key) >= 32) {
            return substr($key, 0, 32);
        }

        return null;
    }
}