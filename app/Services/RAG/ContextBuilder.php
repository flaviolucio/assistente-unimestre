<?php

declare(strict_types=1);

namespace App\Services\RAG;

use App\Core\Logger;
use App\Repositories\KnowledgeDocumentRepository;
use App\Services\Copilot\EvidenceType;

/**
 * Constrói o bloco de contexto estruturado entregue à IA a partir dos
 * resultados da recuperação local (RetrievalService).
 *
 * Regras:
 *  - Cada fonte é identificada explicitamente (=== FONTE n === ... === FIM DA FONTE n ===).
 *  - O tipo é derivado da base: OFICIAL UNIMESTRE (knowledge_documents) ou
 *    PROCEDIMENTO INTERNO (internal_solutions), nunca do texto do LLM.
 *  - Deduplicação conservadora por documento: mantém o chunk/fonte de maior
 *    pontuação por documento (a lista já vem ordenada por score decrescente).
 *  - Orçamento de tokens: nunca ultrapassa o teto; fontes adicionais são
 *    interrompidas antes de estourar o orçamento (sem cortes parciais).
 */
final class ContextBuilder
{
    private ChunkingService $chunker;

    public function __construct(?ChunkingService $chunker = null)
    {
        $this->chunker = $chunker ?? new ChunkingService();
    }

    /**
     * @param array<int, SearchResult> $results
     * @param int $budgetTokens
     * @return array{
     *     context: string,
     *     sources: array<int, array<string, mixed>>,
     *     total_tokens: int,
     *     deduped: int,
     *     dropped_by_budget: int
     * }
     */
    public function build(array $results, int $budgetTokens): array
    {
        $budgetTokens = max(1, $budgetTokens);

        $seenDocument = [];
        $selected = [];
        $dedupedByDocument = 0;

        foreach ($results as $result) {
            $documentId = $result->documentId();
            $solutionId = $result->solutionId();

            $dedupKey = $solutionId !== null
                ? 'solution:' . $solutionId
                : 'document:' . ($documentId !== null ? $documentId : $result->key());

            if (isset($seenDocument[$dedupKey])) {
                $dedupedByDocument++;
                continue;
            }

            $seenDocument[$dedupKey] = true;
            $selected[] = $this->decorate($result);
        }

        $blocks = [];
        $usedTokens = 0;
        $droppedByBudget = 0;
        $sourceNumber = 0;

        foreach ($selected as $source) {
            $block = $this->formatBlock($sourceNumber + 1, $source);
            $blockTokens = $this->chunker->estimateTokens($block);

            if ($usedTokens > 0 && $usedTokens + $blockTokens > $budgetTokens) {
                $droppedByBudget++;
                continue;
            }

            $sourceNumber++;
            $blocks[] = $block;
            $usedTokens += $blockTokens;
        }

        $context = $blocks !== []
            ? implode("\n\n", $blocks)
            : '';

        Logger::info('rag', 'Contexto construído para o prompt', [
            'selected' => count($selected),
            'included' => $sourceNumber,
            'deduped' => $dedupedByDocument,
            'dropped_by_budget' => $droppedByBudget,
            'context_tokens' => $usedTokens,
            'budget_tokens' => $budgetTokens,
        ]);

        return [
            'context' => $context,
            'sources' => array_slice($selected, 0, $sourceNumber),
            'total_tokens' => $usedTokens,
            'deduped' => $dedupedByDocument,
            'dropped_by_budget' => $droppedByBudget,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decorate(SearchResult $result): array
    {
        $url = null;

        if ($result->documentId() !== null) {
            $document = (new KnowledgeDocumentRepository())->find($result->documentId());

            if ($document !== null) {
                $url = $document['url'] !== null && $document['url'] !== ''
                    ? (string) $document['url']
                    : null;
            }
        }

        return [
            'key' => $result->key(),
            'strategy' => $result->strategy(),
            'document_id' => $result->documentId(),
            'chunk_id' => $result->chunkId(),
            'solution_id' => $result->solutionId(),
            'title' => $result->title(),
            'source_type' => $result->sourceType(),
            'category' => $result->category(),
            'subcategory' => $result->subcategory(),
            'content' => $result->content(),
            'url' => $url,
            'updated_at' => $result->updatedAt(),
            'matched_terms' => $result->matchedTerms(),
            'relevance_score' => $result->finalScore(),
        ];
    }

    /**
     * @param array<string, mixed> $source
     */
    private function formatBlock(int $number, array $source): string
    {
        $tipo = EvidenceType::plainBadge(EvidenceType::fromSource($source));

        $category = (string) ($source['category'] ?? '');
        $category = $category !== '' ? $category : 'Não informada';

        $url = (string) ($source['url'] ?? '');
        $url = $url !== '' ? $url : 'Não aplicável';

        return "=== FONTE {$number} ===\n"
            . "TIPO: {$tipo}\n"
            . 'TÍTULO: ' . (string) $source['title'] . "\n"
            . "CATEGORIA: {$category}\n"
            . "URL: {$url}\n"
            . "CONTEÚDO:\n"
            . (string) $source['content'] . "\n"
            . "=== FIM DA FONTE {$number} ===";
    }
}