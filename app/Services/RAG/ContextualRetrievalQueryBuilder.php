<?php

declare(strict_types=1);

namespace App\Services\RAG;

/**
 * Monta a consulta de RETRIEVAL de uma mensagem dentro de uma conversa.
 *
 * Determinístico, local, sem IA e sem chamadas externas.
 *
 * Problema resolvido: numa investigação, respostas como "Sim." ou "Não."
 * não têm termos pesquisáveis. A consulta documental precisa manter os
 * termos do PROBLEMA, não da última frase.
 *
 * Regras:
 *  1. Sem histórico → a mensagem atual, sem alterações (comportamento
 *     anterior preservado para perguntas independentes).
 *  2. Mensagem que não depende do histórico (≥ SELF_CONTAINED_MIN_TERMS
 *     termos significativos e sem referência anafórica, ver
 *     dependsOnHistory()) → a mensagem atual, sem alterações (uma pergunta
 *     nova não é "contaminada" pelo problema anterior).
 *  3. Caso contrário (follow-up curto) → termos significativos, nesta
 *     prioridade e sem repetição:
 *        mensagem atual → problema original → última pergunta do assistente
 *     limitados a MAX_TERMS.
 *
 * "Termo significativo" = termo do SearchQueryService (mesmas stopwords e
 * tamanho mínimo da busca) que não seja palavra de preenchimento de
 * conversa (FILLER_WORDS).
 */
final class ContextualRetrievalQueryBuilder
{
    public const SELF_CONTAINED_MIN_TERMS = 4;

    public const MAX_TERMS = 12;

    /** Termos de uma mensagem do assistente considerados (evita respostas longas dominarem). */
    public const MAX_ASSISTANT_TERMS = 6;

    /** Palavras que mantêm a CONVERSA, mas não servem como consulta documental. */
    public const FILLER_WORDS = [
        'sim', 'nao', 'não', 'ok', 'okay', 'blz', 'beleza', 'certo', 'entendi', 'entendido',
        'obrigado', 'obrigada', 'valeu', 'oi', 'ola', 'olá', 'bom', 'boa', 'dia', 'tarde', 'noite',
        'isso', 'exato', 'exatamente', 'claro', 'correto', 'perfeito', 'talvez', 'acho', 'sei',
        'ainda', 'tambem', 'também', 'aqui', 'agora', 'ja', 'já', 'tudo', 'nada', 'pode', 'vou',
        'verifiquei', 'conferi', 'feito', 'pronto',
    ];

    /**
     * Referências explícitas ao que foi dito antes (anáfora). Uma mensagem que
     * as contém depende do histórico mesmo tendo vários termos, por exemplo
     * "Por quanto tempo essa solicitação é válida?".
     * "esta" (sem acento) fica de fora: é ambígua com "está".
     */
    public const ANAPHORIC_WORDS = [
        'isso', 'isto', 'aquilo', 'disso', 'nisso', 'daquilo',
        'esse', 'essa', 'esses', 'essas', 'desse', 'dessa', 'desses', 'dessas', 'nesse', 'nessa',
        'este', 'estes', 'estas', 'deste', 'desta', 'neste', 'nesta',
        'ele', 'ela', 'eles', 'elas', 'dele', 'dela', 'deles', 'delas', 'nele', 'nela',
    ];

    /** Primeira palavra que indica continuação ("E onde fica…?", "Então…"). */
    public const CONTINUATION_STARTERS = ['e', 'então', 'entao', 'mas'];

    private SearchQueryService $query;

    public function __construct(?SearchQueryService $query = null)
    {
        $this->query = $query ?? new SearchQueryService();
    }

    /**
     * @param array<int, array{role?: string, content?: string}> $history mensagens anteriores (cronológicas), sem a atual
     */
    public function build(string $currentMessage, array $history, ?string $problemStatement = null): string
    {
        $currentMessage = trim($currentMessage);
        $currentTerms = $this->meaningfulTerms($currentMessage);

        if ($history === [] && ($problemStatement === null || trim($problemStatement) === '')) {
            return $currentMessage;
        }

        if (!$this->dependsOnHistory($currentMessage)) {
            return $currentMessage;
        }

        $problem = $problemStatement !== null && trim($problemStatement) !== ''
            ? $problemStatement
            : $this->firstUserMessage($history);

        $terms = $currentTerms;

        foreach ($this->meaningfulTerms((string) $problem) as $term) {
            $terms[] = $term;
        }

        $lastAssistant = $this->lastMessageOf($history, 'assistant');

        foreach (array_slice($this->meaningfulTerms((string) $lastAssistant), 0, self::MAX_ASSISTANT_TERMS) as $term) {
            $terms[] = $term;
        }

        $terms = array_slice(array_values(array_unique($terms)), 0, self::MAX_TERMS);

        // Nada aproveitável em lugar nenhum: devolve a mensagem original
        // (o RetrievalService tratará como consulta vazia/sem resultado).
        return $terms === [] ? $currentMessage : implode(' ', $terms);
    }

    /**
     * A mensagem é continuação da conversa (follow-up)?
     *
     * Regra conservadora e determinística (sem IA):
     *   existe histórico útil
     *   E ( mensagem curta: < SELF_CONTAINED_MIN_TERMS termos significativos
     *       OU referência anafórica explícita: "isso", "essa", "ele"… ou começa com "E …" )
     *
     * Uma pergunta completa e sem referência ao que veio antes é tratada como
     * INDEPENDENTE, mesmo que a conversa já tenha histórico.
     *
     * @param array<int, array{role?: string, content?: string}> $history
     */
    public function isFollowUp(string $currentMessage, array $history): bool
    {
        return $this->hasUsefulHistory($history) && $this->dependsOnHistory($currentMessage);
    }

    /** A mensagem, por si só, precisa do histórico para ser entendida? */
    public function dependsOnHistory(string $currentMessage): bool
    {
        if (count($this->meaningfulTerms($currentMessage)) < self::SELF_CONTAINED_MIN_TERMS) {
            return true;
        }

        $words = preg_split('/\s+/u', $this->query->clean($currentMessage)) ?: [];

        if ($words !== [] && in_array($words[0], self::CONTINUATION_STARTERS, true)) {
            return true;
        }

        return array_intersect($words, self::ANAPHORIC_WORDS) !== [];
    }

    /**
     * Há contexto conversacional que permita interpretar uma resposta curta?
     * (ao menos uma mensagem anterior do usuário com termo significativo)
     *
     * @param array<int, array{role?: string, content?: string}> $history
     */
    public function hasUsefulHistory(array $history): bool
    {
        foreach ($history as $message) {
            if (($message['role'] ?? '') === 'user' && $this->meaningfulTerms((string) ($message['content'] ?? '')) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    public function meaningfulTerms(string $text): array
    {
        $terms = $this->query->normalize($text)['terms'];

        return array_values(array_filter(
            $terms,
            static fn (string $term): bool => !in_array($term, self::FILLER_WORDS, true)
        ));
    }

    /**
     * @param array<int, array{role?: string, content?: string}> $history
     */
    private function firstUserMessage(array $history): ?string
    {
        foreach ($history as $message) {
            if (($message['role'] ?? '') === 'user' && $this->meaningfulTerms((string) ($message['content'] ?? '')) !== []) {
                return (string) $message['content'];
            }
        }

        return null;
    }

    /**
     * @param array<int, array{role?: string, content?: string}> $history
     */
    private function lastMessageOf(array $history, string $role): ?string
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') === $role) {
                return (string) ($history[$i]['content'] ?? '');
            }
        }

        return null;
    }
}
