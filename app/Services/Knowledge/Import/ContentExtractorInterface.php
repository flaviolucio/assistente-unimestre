<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Import;

/**
 * Extrator de conteúdo de UM formato (html, dokuwiki, text, markdown, pdf).
 *
 * Todos devolvem o MESMO documento normalizado, que segue para o pipeline
 * único do OfficialImportService (dry run → deduplicação → importManual →
 * chunking já aprovado). Nenhum extrator faz requisições de rede ou usa IA.
 *
 * Documento normalizado (chaves sempre presentes):
 *   title, content, url, url_source, category, subcategory,
 *   published_at, source_modified_at, source_modified_type,
 *   hosts (hosts citados em links), media_refs (arquivos de mídia citados),
 *   metadata (array livre, ex.: namespace, pdf_pages),
 *   quality (null, ou para PDF: text|ocr_needed|failed|unsupported)
 */
interface ContentExtractorInterface
{
    /** Formato produzido (html|dokuwiki|text|markdown|pdf). */
    public function format(): string;

    /**
     * @param array<string, mixed> $context ex.: relative, dokuwiki_id, link_map
     * @return array<string, mixed>
     */
    public function extract(string $raw, array $context = []): array;
}
