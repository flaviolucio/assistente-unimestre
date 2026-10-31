<?php

declare(strict_types=1);

namespace App\Services\AI;

interface AIProviderInterface
{
    /**
     * Identificador estavel do provider (ex.: "openrouter").
     */
    public function name(): string;

    /**
     * Modelo ativamente configurado (ex.: "openrouter/free").
     */
    public function model(): ?string;

    /**
     * Conversa de chat no formato OpenAI (lista de {role,content}).
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    public function chat(array $messages): AIResponse;

    /**
     * Verifica conectividade/autenticacao com uma chamada minima.
     */
    public function testConnection(): AIResponse;
}