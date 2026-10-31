<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Import;

/**
 * Política de URLs oficiais do UNIMESTRE para a importação em massa.
 *
 * - Allowlist EXATA de hosts (config knowledge.bulk_import.allowed_hosts).
 * - Só http/https, sem usuário/senha, sem porta, sem IP literal.
 * - Canonicalização determinística para deduplicação:
 *     https, host minúsculo, "www." removido, sem fragmento, sem parâmetros
 *     de rastreamento (utm_*, fbclid, gclid…), query ordenada, barras
 *     duplicadas colapsadas e barra final em caminhos sem extensão
 *     (padrão das páginas WordPress da Base de Conhecimento).
 *
 * Importante: a importação usa ARQUIVOS LOCAIS. Esta classe apenas valida e
 * normaliza a URL oficial declarada no arquivo; nenhuma requisição é feita.
 */
final class OfficialUrlPolicy
{
    private const TRACKING_PARAMS = ['fbclid', 'gclid', 'mc_cid', 'mc_eid', 'ref', '_ga'];

    /** @var array<int, string> */
    private array $allowedHosts;

    public function __construct(?array $allowedHosts = null)
    {
        $hosts = $allowedHosts ?? (array) config('knowledge.bulk_import.allowed_hosts', []);
        $this->allowedHosts = array_values(array_unique(array_map(static fn ($h): string => strtolower(trim((string) $h)), $hosts)));
    }

    /** Host pertence à allowlist oficial? */
    public function isAllowed(string $url): bool
    {
        $parts = $this->parse($url);

        return $parts !== null && in_array($parts['host'], $this->allowedHosts, true);
    }

    /**
     * Subdomínio *.unimestre.com fora da allowlist: não é importado, mas é
     * listado no relatório para análise humana.
     */
    public function isUnlistedOfficialSubdomain(string $url): bool
    {
        $parts = $this->parse($url);

        return $parts !== null
            && !in_array($parts['host'], $this->allowedHosts, true)
            && ($parts['host'] === 'unimestre.com' || str_ends_with($parts['host'], '.unimestre.com'));
    }

    /** URL canônica (string vazia se inválida/não http(s)). */
    public function canonicalize(string $url): string
    {
        $parts = $this->parse($url);

        if ($parts === null) {
            return '';
        }

        $host = $parts['host'];

        if (str_starts_with($host, 'www.') && in_array(substr($host, 4), $this->allowedHosts, true)) {
            $host = substr($host, 4);
        }

        $path = preg_replace('#/{2,}#', '/', $parts['path'] === '' ? '/' : $parts['path']) ?? '/';
        $lastSegment = (string) substr($path, (int) strrpos($path, '/') + 1);

        if (!str_ends_with($path, '/') && !str_contains($lastSegment, '.')) {
            $path .= '/';
        }

        $query = [];

        if ($parts['query'] !== '') {
            parse_str($parts['query'], $query);

            foreach (array_keys($query) as $key) {
                $k = strtolower((string) $key);

                if (str_starts_with($k, 'utm_') || in_array($k, self::TRACKING_PARAMS, true)) {
                    unset($query[$key]);
                }
            }

            ksort($query);
        }

        return 'https://' . $host . $path . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    /** Variantes a consultar no banco (com/sem barra final), para achar documentos antigos. */
    public function lookupVariants(string $canonical): array
    {
        if ($canonical === '') {
            return [];
        }

        $variants = [$canonical];

        if (str_contains($canonical, '?')) {
            return $variants;
        }

        $variants[] = str_ends_with($canonical, '/') ? rtrim($canonical, '/') : $canonical . '/';

        return array_values(array_unique($variants));
    }

    /**
     * @return array{host: string, path: string, query: string}|null
     */
    private function parse(string $url): ?array
    {
        $url = trim($url);

        if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || filter_var($host, FILTER_VALIDATE_IP) !== false || str_starts_with($host, '[')) {
            return null;
        }

        return [
            'host' => $host,
            'path' => (string) ($parts['path'] ?? '/'),
            'query' => (string) ($parts['query'] ?? ''),
        ];
    }
}
