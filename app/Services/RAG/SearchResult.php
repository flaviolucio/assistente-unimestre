<?php

declare(strict_types=1);

namespace App\Services\RAG;

final class SearchResult
{
    private string $key;
    private string $strategy;
    private ?int $chunkId;
    private ?int $documentId;
    private ?int $solutionId;
    private string $title;
    private string $sourceType;
    private ?string $category;
    private ?string $subcategory;
    private string $content;
    private string $updatedAt;
    private array $matchedTerms;
    private float $fulltextRaw;
    private float $finalScore = 0.0;
    private array $breakdown = [];

    public function __construct(
        string $key,
        string $strategy,
        ?int $chunkId,
        ?int $documentId,
        ?int $solutionId,
        string $title,
        string $sourceType,
        ?string $category,
        ?string $subcategory,
        string $content,
        string $updatedAt,
        array $matchedTerms,
        float $fulltextRaw
    ) {
        $this->key = $key;
        $this->strategy = $strategy;
        $this->chunkId = $chunkId;
        $this->documentId = $documentId;
        $this->solutionId = $solutionId;
        $this->title = $title;
        $this->sourceType = $sourceType;
        $this->category = $category;
        $this->subcategory = $subcategory;
        $this->content = $content;
        $this->updatedAt = $updatedAt;
        $this->matchedTerms = $matchedTerms;
        $this->fulltextRaw = $fulltextRaw;
    }

    public static function fromCandidate(array $candidate): self
    {
        return new self(
            (string) $candidate['key'],
            (string) $candidate['strategy'],
            isset($candidate['chunk_id']) ? (int) $candidate['chunk_id'] : null,
            isset($candidate['document_id']) ? (int) $candidate['document_id'] : null,
            isset($candidate['solution_id']) ? (int) $candidate['solution_id'] : null,
            (string) $candidate['title'],
            (string) $candidate['source_type'],
            $candidate['category'] !== null ? (string) $candidate['category'] : null,
            $candidate['subcategory'] !== null ? (string) $candidate['subcategory'] : null,
            (string) $candidate['content'],
            (string) $candidate['updated_at'],
            (array) ($candidate['matched_terms'] ?? []),
            (float) ($candidate['fulltext_raw'] ?? 0.0)
        );
    }

    public function finalize(float $score, array $breakdown): void
    {
        $this->finalScore = $score;
        $this->breakdown = $breakdown;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function sourceType(): string
    {
        return $this->sourceType;
    }

    public function strategy(): string
    {
        return $this->strategy;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function fulltextRaw(): float
    {
        return $this->fulltextRaw;
    }

    public function finalScore(): float
    {
        return $this->finalScore;
    }

    public function category(): ?string
    {
        return $this->category;
    }

    public function subcategory(): ?string
    {
        return $this->subcategory;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function matchedTerms(): array
    {
        return $this->matchedTerms;
    }

    public function updatedAt(): string
    {
        return $this->updatedAt;
    }

    public function documentId(): ?int
    {
        return $this->documentId;
    }

    public function chunkId(): ?int
    {
        return $this->chunkId;
    }

    public function solutionId(): ?int
    {
        return $this->solutionId;
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'strategy' => $this->strategy,
            'chunk_id' => $this->chunkId,
            'document_id' => $this->documentId,
            'solution_id' => $this->solutionId,
            'title' => $this->title,
            'source_type' => $this->sourceType,
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'content' => $this->content,
            'updated_at' => $this->updatedAt,
            'matched_terms' => $this->matchedTerms,
            'fulltext_raw' => $this->fulltextRaw,
            'score' => $this->finalScore,
            'breakdown' => $this->breakdown,
        ];
    }
}