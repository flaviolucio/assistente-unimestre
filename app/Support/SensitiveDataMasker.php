<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Mascara dados sensíveis antes de qualquer texto exportável (Etapa 7.5):
 * senhas, tokens, chaves de API, cookies, segredos, JWT, valores longos
 * aleatórios e dados pessoais desnecessários (CPF, cartão, e-mail).
 *
 * É conservador: prefere omitir a vazar. Não altera o banco — só a cópia
 * que vai para o resumo/texto de suporte.
 */
final class SensitiveDataMasker
{
    private int $count = 0;

    public function count(): int
    {
        return $this->count;
    }

    public function mask(string $text): string
    {
        $rules = [
            // Bearer antes de "chave: valor" (senão só a palavra Bearer seria omitida)
            '/\bBearer\s+[A-Za-z0-9._~+\/=-]{8,}/u' => 'Bearer [OMITIDO]',
            // chave: valor (senha=..., token: ..., Authorization: ..., Cookie: ...)
            '/\b(senha|password|passwd|pwd|token|access[_-]?token|refresh[_-]?token|api[_ -]?key|chave(?:\s+de\s+api)?|secret|segredo|client[_-]?secret|authorization|set-cookie|cookie|session(?:[_-]?id)?|phpsessid)\b((?:\s+[\p{L}\d]+){0,3}?(?:\s*[:=]\s*|\s+(?:é|eh|era|seria|:)\s+))("[^"]*"|\'[^\']*\'|[^\s,;]+)/iu' => '$1$2[OMITIDO]',
            // prefixos conhecidos de chaves de provedores
            '/\b(?:sk-(?:or-)?[A-Za-z0-9_-]{10,}|gsk_[A-Za-z0-9]{16,}|nvapi-[A-Za-z0-9_-]{16,}|AIza[0-9A-Za-z_-]{20,}|AKIA[0-9A-Z]{16}|ghp_[A-Za-z0-9]{20,}|xox[abpr]-[A-Za-z0-9-]{10,})\b/u' => '[CHAVE OMITIDA]',
            // JWT
            '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/u' => '[TOKEN OMITIDO]',
            // valores longos aleatórios (hex ≥ 32 / base64 ≥ 40)
            '/\b[a-f0-9]{32,}\b/iu' => '[VALOR OMITIDO]',
            '/\b(?=[A-Za-z0-9+\/]*\d)(?=[A-Za-z0-9+\/]*[A-Za-z])[A-Za-z0-9+\/]{40,}={0,2}/u' => '[VALOR OMITIDO]',
            // dados pessoais
            '/\b\d{3}\.\d{3}\.\d{3}-\d{2}\b|\b\d{11}\b/u' => '[CPF OMITIDO]',
            '/\b(?:\d[ -]?){13,19}\b/u' => '[NÚMERO OMITIDO]',
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/u' => '[E-MAIL OMITIDO]',
        ];

        foreach ($rules as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text, -1, $n);
            $this->count += $n;
        }

        return $text;
    }

    /**
     * Aplica mask() em todas as strings de uma estrutura.
     *
     * @param mixed $value
     * @return mixed
     */
    public function maskDeep($value)
    {
        if (is_string($value)) {
            return $this->mask($value);
        }

        if (is_array($value)) {
            return array_map(fn ($item) => $this->maskDeep($item), $value);
        }

        return $value;
    }
}
