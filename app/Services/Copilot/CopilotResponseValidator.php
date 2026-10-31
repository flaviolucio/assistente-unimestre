<?php

declare(strict_types=1);

namespace App\Services\Copilot;

use App\Support\SourceUrl;

/**
 * Valida a saída do modelo antes de qualquer exibição ou persistência
 * (Etapa 7.1). Nada do texto livre do modelo é confiado cegamente:
 *
 *  - aceita o contrato JSON (mode/answer/hypotheses/next_question/...) e cai
 *    para texto livre quando o modelo não o respeita (modelos gratuitos);
 *  - source_ids e [Fonte n] só sobrevivem se apontarem para uma fonte
 *    realmente enviada pelo backend;
 *  - URLs só sobrevivem se forem a URL confiável de uma dessas fontes;
 *  - porcentagens de confiança são removidas (o status é qualitativo);
 *  - hipótese "confirmed" sem evidência referenciada é rebaixada;
 *  - hipóteses são sempre rotuladas como HIPÓTESE DA IA, nunca como fonte.
 */
final class CopilotResponseValidator
{
    public const MODE_ANSWER = 'answer';
    public const MODE_DIAGNOSTIC = 'diagnostic';

    public const HYPOTHESIS_STATUSES = ['possible', 'supported', 'weakened', 'rejected', 'confirmed'];

    public const STATUS_LABELS = [
        'possible' => 'Possível',
        'supported' => 'Sustentada',
        'weakened' => 'Enfraquecida',
        'rejected' => 'Rejeitada',
        'confirmed' => 'Confirmada',
    ];

    public const MAX_HYPOTHESES = 5;

    public const MSG_INVALID_OUTPUT = 'A resposta gerada pela IA veio em um formato inválido e foi descartada por segurança. Reformule a pergunta ou tente novamente.';

    public const URL_REMOVED = '(link removido: não pertence às fontes consultadas)';

    /**
     * Contrato de saída acrescentado ao prompt de sistema. Fica DEPOIS da
     * regra de confiança e ANTES do CONTEXTO.
     */
    public const OUTPUT_CONTRACT = "FORMATO DE SAÍDA (obrigatório): responda SOMENTE com um objeto JSON válido, sem texto antes ou depois:\n"
        . "{\"mode\": \"answer\" ou \"diagnostic\", \"answer\": \"texto em markdown para o usuário, citando [Fonte n]\", "
        . "\"hypotheses\": [{\"id\": \"h12 (id de hipótese existente no ESTADO DO DIAGNÓSTICO, ou omita se for nova)\", \"title\": \"...\", \"status\": \"possible|supported|weakened|rejected|confirmed\", \"reason\": \"...\", \"source_ids\": [n]}], "
        . "\"next_question\": \"uma pergunta objetiva ao usuário ou null\", \"recommended_action\": \"texto ou null\", "
        . "\"resolved\": false, \"confirmed_cause\": null, \"source_ids\": [n], "
        . "\"escalation\": {\"recommended\": false, \"reason\": null}, \"source_conflicts\": [{\"source_id\": n, \"claim\": \"o que a fonte afirma\"}]}\n"
        . "Regras do formato:\n"
        . "- \"answer\": pergunta direta ou pedido de procedimento. \"diagnostic\": problema técnico que exige investigação.\n"
        . "- Em \"diagnostic\", liste hipóteses (máximo 5) e faça UMA pergunta por vez em next_question. Não repita perguntas já feitas (listadas no ESTADO DO DIAGNÓSTICO); use as respostas do usuário para atualizar o status das hipóteses existentes, mantendo o mesmo id.\n"
        . "- Hipóteses são raciocínio seu, não documentação. Não use porcentagens nem notas de confiança.\n"
        . "- \"confirmed\" e \"resolved\": true só com evidência explícita (fonte ou resposta do usuário). Na dúvida, mantenha \"possible\".\n"
        . "- source_ids só podem conter números de FONTE presentes no CONTEXTO. Não invente fontes nem URLs.\n"
        . "- Não invente menus, campos, comandos ou telas. Se faltar evidência, diga isso no answer.
"
        . "- escalation.recommended = true só quando a investigação não puder avançar com segurança (sem evidência, verificações esgotadas, depende de acesso ou análise interna da UNIMESTRE, erro aparente do produto, documentação orienta o suporte). Nunca invente uma solução para evitar escalar.
"
        . "- source_conflicts: só quando fontes de tipos diferentes afirmam coisas divergentes; um item por fonte (mínimo 2). Caso contrário, [].";

    /**
     * @param array<int, array<string, mixed>> $sources fontes enviadas à IA (ordem = número da fonte)
     * @return array<string, mixed>
     */
    public function validate(string $raw, array $sources, bool $userEvidence = false): array
    {
        $sourceCount = count($sources);
        $trustedUrls = $this->trustedUrls($sources);
        $warnings = [];

        $decoded = $this->decodeJson($raw);

        if ($decoded === null) {
            if ($this->looksLikeJson($raw)) {
                // JSON quebrado/truncado: nunca exibir o bruto ao usuário.
                return $this->result(false, self::MODE_ANSWER, self::MSG_INVALID_OUTPUT, [], null, null, false, null, [], ['invalid_json']);
            }

            $text = $this->sanitizeText($raw, $sourceCount, $trustedUrls, $warnings);
            $warnings[] = 'free_text';

            return $this->result(false, self::MODE_ANSWER, $text, [], null, null, false, null, $this->citedIds($text, $sourceCount), $warnings);
        }

        $mode = ($decoded['mode'] ?? '') === self::MODE_DIAGNOSTIC ? self::MODE_DIAGNOSTIC : self::MODE_ANSWER;

        $answer = $this->sanitizeText($this->stringOrNull($decoded['answer'] ?? ($decoded['summary'] ?? null)) ?? '', $sourceCount, $trustedUrls, $warnings);
        $nextQuestion = $this->optionalText($decoded['next_question'] ?? null, $sourceCount, $trustedUrls, $warnings);
        $action = $this->optionalText($decoded['recommended_action'] ?? null, $sourceCount, $trustedUrls, $warnings);
        $cause = $this->optionalText($decoded['confirmed_cause'] ?? null, $sourceCount, $trustedUrls, $warnings);

        $hypotheses = [];

        foreach (array_slice(is_array($decoded['hypotheses'] ?? null) ? $decoded['hypotheses'] : [], 0, self::MAX_HYPOTHESES) as $item) {
            $hypothesis = $this->validateHypothesis($item, $sourceCount, $trustedUrls, $warnings, $userEvidence);

            if ($hypothesis !== null) {
                $hypotheses[] = $hypothesis;
            }
        }

        $sourceIds = $this->validIds($decoded['source_ids'] ?? [], $sourceCount, $warnings);
        $resolved = ($decoded['resolved'] ?? false) === true;
        $hasConfirmed = in_array('confirmed', array_column($hypotheses, 'status'), true);

        if ($resolved && ($cause === null || (!$hasConfirmed && $sourceIds === []))) {
            // "Resolvido" exige causa declarada e evidência referenciada.
            $resolved = false;
            $warnings[] = 'resolved_without_evidence';
        }

        if (!$resolved) {
            $cause = $hasConfirmed ? $cause : null;
        }

        if ($answer === '' && $nextQuestion === null && $hypotheses === []) {
            return $this->result(false, self::MODE_ANSWER, self::MSG_INVALID_OUTPUT, [], null, null, false, null, [], array_merge($warnings, ['empty_answer']));
        }

        $sourceIds = array_values(array_unique(array_merge($sourceIds, $this->citedIds($answer, $sourceCount))));
        sort($sourceIds);

        $out = $this->result(true, $mode, $answer, $hypotheses, $nextQuestion, $action, $resolved, $cause, $sourceIds, $warnings);

        // 7.5: sugestão de escalonamento (nunca efetiva sozinha) e divergências.
        $escalation = is_array($decoded['escalation'] ?? null) ? $decoded['escalation'] : [];
        $out['escalation_recommended'] = !$resolved && ($escalation['recommended'] ?? false) === true;
        $out['escalation_reason'] = $out['escalation_recommended']
            ? $this->optionalText($escalation['reason'] ?? null, $sourceCount, $trustedUrls, $warnings)
            : null;
        $out['source_conflicts'] = $this->validateConflicts($decoded['source_conflicts'] ?? [], $sourceCount, $trustedUrls, $warnings);
        $out['warnings'] = array_values(array_unique($warnings));

        return $out;
    }

    /**
     * Texto markdown exibido no chat (e gravado em messages.content), montado
     * pelo backend a partir da estrutura validada.
     *
     * @param array<string, mixed> $validated
     */
    public function render(array $validated): string
    {
        $parts = [];
        $answer = trim((string) ($validated['answer'] ?? ''));

        if ($answer !== '') {
            $parts[] = $answer;
        }

        $hypotheses = (array) ($validated['hypotheses'] ?? []);

        if ($hypotheses !== []) {
            $lines = ['**' . EvidenceType::badge(EvidenceType::AI_HYPOTHESIS) . ' — possíveis causas (raciocínio da IA, não é documentação):**'];

            foreach ($hypotheses as $hypothesis) {
                $line = '- **' . $hypothesis['title'] . '** — ' . self::STATUS_LABELS[$hypothesis['status']];
                $reason = trim((string) ($hypothesis['reason'] ?? ''));

                if ($reason !== '') {
                    $line .= ': ' . $reason;
                }

                foreach ($hypothesis['source_ids'] as $id) {
                    if (!str_contains($line, '[Fonte ' . $id . ']')) {
                        $line .= ' [Fonte ' . $id . ']';
                    }
                }

                $lines[] = $line;
            }

            $parts[] = implode("\n", $lines);
        }

        if (!empty($validated['resolved']) && ($validated['confirmed_cause'] ?? null) !== null) {
            $parts[] = '**Causa confirmada:** ' . $validated['confirmed_cause'];
        }

        if (($validated['recommended_action'] ?? null) !== null) {
            $parts[] = '**Próxima ação sugerida:** ' . $validated['recommended_action'];
        }

        if (!empty($validated['escalation_recommended'])) {
            $parts[] = '**' . EvidenceType::badge(EvidenceType::AI_HYPOTHESIS) . ' — recomendação:** com as informações atuais, não foi possível confirmar a causa. Recomendo preparar o escalonamento deste caso'
                . (($validated['escalation_reason'] ?? null) !== null ? ' (' . $validated['escalation_reason'] . ')' : '') . '.';
        }

        if (count((array) ($validated['source_conflicts'] ?? [])) >= 2) {
            $parts[] = '**Há divergência entre as fontes consultadas:** ' . implode('; ', array_map(
                static fn (array $c): string => '[Fonte ' . $c['source_id'] . '] ' . $c['claim'],
                (array) $validated['source_conflicts']
            )) . '.';
        }

        if (($validated['next_question'] ?? null) !== null) {
            $parts[] = '**Pergunta de diagnóstico:** ' . $validated['next_question'];
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param mixed $item
     * @param array<int, string> $trustedUrls
     * @param array<int, string> $warnings
     * @return array<string, mixed>|null
     */
    private function validateHypothesis($item, int $sourceCount, array $trustedUrls, array &$warnings, bool $userEvidence = false): ?array
    {
        if (!is_array($item)) {
            return null;
        }

        $title = $this->stripPercentages($this->oneLine((string) ($item['title'] ?? '')), $warnings, true);

        if ($title === '') {
            return null;
        }

        $status = (string) ($item['status'] ?? 'possible');

        if (!in_array($status, self::HYPOTHESIS_STATUSES, true)) {
            $status = 'possible';
            $warnings[] = 'invalid_hypothesis_status';
        }

        $reason = $this->sanitizeText($this->stripPercentages((string) ($item['reason'] ?? ''), $warnings, true), $sourceCount, $trustedUrls, $warnings);
        $sourceIds = $this->validIds($item['source_ids'] ?? [], $sourceCount, $warnings);

        // "confirmed" exige fonte citada OU resposta do usuário neste turno
        // (a uma pergunta de diagnóstico) com justificativa.
        if ($status === 'confirmed' && $sourceIds === [] && !($userEvidence && $reason !== '')) {
            $status = 'supported';
            $warnings[] = 'confirmed_without_evidence';
        }

        // "rejected" sem nenhuma justificativa não descarta a hipótese.
        if ($status === 'rejected' && $sourceIds === [] && $reason === '') {
            $status = 'weakened';
            $warnings[] = 'rejected_without_evidence';
        }

        $ref = null;

        if (isset($item['id']) && preg_match('/^h?(\d{1,9})$/i', trim((string) $item['id']), $m)) {
            $ref = (int) $m[1];
        }

        return [
            'ref' => $ref,
            'title' => mb_substr($title, 0, 160),
            'status' => $status,
            'status_label' => self::STATUS_LABELS[$status],
            'reason' => mb_substr($reason, 0, 600),
            'source_ids' => $sourceIds,
            'evidence_type' => EvidenceType::AI_HYPOTHESIS,
        ];
    }

    /**
     * @param array<int, string> $trustedUrls
     * @param array<int, string> $warnings
     */
    private function sanitizeText(string $text, int $sourceCount, array $trustedUrls, array &$warnings): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        // [Fonte n] / [Fonte n, m] inexistentes são removidas.
        $text = (string) preg_replace_callback('/\[\s*Fontes?\s+([0-9][0-9 ,e]*)\]/iu', static function (array $m) use ($sourceCount, &$warnings): string {
            preg_match_all('/\d+/', $m[1], $numbers);
            $valid = array_values(array_filter(array_map('intval', $numbers[0]), static fn (int $n): bool => $n >= 1 && $n <= $sourceCount));

            if (count($valid) !== count($numbers[0])) {
                $warnings[] = 'invalid_citation_removed';
            }

            return implode(' ', array_map(static fn (int $n): string => '[Fonte ' . $n . ']', array_unique($valid)));
        }, $text);

        // Links markdown com URL não confiável: mantém só o rótulo.
        $text = (string) preg_replace_callback('/\[([^\]\n]+)\]\((https?:\/\/[^)\s]+)\)/iu', function (array $m) use ($trustedUrls, &$warnings): string {
            if (in_array($this->normalizeUrl($m[2]), $trustedUrls, true)) {
                return $m[0];
            }

            $warnings[] = 'untrusted_url_removed';

            return $m[1];
        }, $text);

        // URLs soltas fora das fontes.
        $text = (string) preg_replace_callback('/(?<!\()https?:\/\/[^\s<>)\]"]+/iu', function (array $m) use ($trustedUrls, &$warnings): string {
            $url = rtrim($m[0], '.,;:!?');
            $tail = substr($m[0], strlen($url));

            if (in_array($this->normalizeUrl($url), $trustedUrls, true)) {
                return $m[0];
            }

            $warnings[] = 'untrusted_url_removed';

            return self::URL_REMOVED . $tail;
        }, $text);

        $text = $this->stripPercentages($text, $warnings);
        $text = (string) preg_replace('/[ \t]{2,}/u', ' ', $text);

        return trim($text);
    }

    /**
     * Remove notas percentuais de confiança/probabilidade ("confiança: 80%").
     *
     * @param array<int, string> $warnings
     */
    private function stripPercentages(string $text, array &$warnings, bool $strict = false): string
    {
        $clean = preg_replace(
            '/\s*[\(\[]?\s*(?:confian\w*|probabilidade|certeza|chance|plausibilidade)\s*(?:de\s*)?[:=]?\s*(?:~|de\s+)?\d{1,3}(?:[.,]\d+)?\s*%\s*[\)\]]?/iu',
            '',
            $text
        );

        if ($strict) {
            // Em hipóteses, qualquer "(80%)" é nota de confiança.
            $clean = preg_replace('/\s*[\(\[]\s*\d{1,3}(?:[.,]\d+)?\s*%\s*[\)\]]/u', '', (string) $clean);
        }

        if ($clean !== null && $clean !== $text) {
            $warnings[] = 'percentage_removed';

            return $clean;
        }

        return $text;
    }

    /**
     * @param mixed $value
     * @param array<int, string> $trustedUrls
     * @param array<int, string> $warnings
     */
    private function optionalText($value, int $sourceCount, array $trustedUrls, array &$warnings): ?string
    {
        $text = $this->stringOrNull($value);

        if ($text === null) {
            return null;
        }

        $text = $this->sanitizeText($text, $sourceCount, $trustedUrls, $warnings);

        return $text === '' ? null : mb_substr($text, 0, 1000);
    }

    /**
     * @param mixed $value
     */
    private function stringOrNull($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return ($value === '' || strtolower($value) === 'null') ? null : $value;
    }

    /**
     * @param mixed $ids
     * @param array<int, string> $warnings
     * @return array<int, int>
     */
    private function validIds($ids, int $sourceCount, array &$warnings): array
    {
        if (!is_array($ids)) {
            return [];
        }

        $valid = [];

        foreach ($ids as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id >= 1 && (int) $id <= $sourceCount) {
                $valid[] = (int) $id;
            } else {
                $warnings[] = 'invalid_source_id_removed';
            }
        }

        $valid = array_values(array_unique($valid));
        sort($valid);

        return $valid;
    }

    /**
     * @return array<int, int>
     */
    private function citedIds(string $text, int $sourceCount): array
    {
        preg_match_all('/\[Fonte (\d+)\]/u', $text, $m);
        $ids = array_values(array_unique(array_filter(array_map('intval', $m[1]), static fn (int $n): bool => $n >= 1 && $n <= $sourceCount)));
        sort($ids);

        return $ids;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $raw): ?array
    {
        $text = trim($raw);
        $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/iu', '', $text);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        if (!is_array($decoded) || (!array_key_exists('mode', $decoded) && !array_key_exists('answer', $decoded))) {
            return null;
        }

        return $decoded;
    }

    private function looksLikeJson(string $raw): bool
    {
        $text = ltrim((string) preg_replace('/^\s*```(?:json)?/iu', '', $raw));

        return str_starts_with($text, '{') && (str_contains($text, '"mode"') || str_contains($text, '"answer"'));
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @return array<int, string>
     */
    private function trustedUrls(array $sources): array
    {
        $urls = [];

        foreach ($sources as $source) {
            if (SourceUrl::isTrusted($source)) {
                $urls[] = $this->normalizeUrl((string) $source['url']);
            }
        }

        return $urls;
    }

    private function normalizeUrl(string $url): string
    {
        return rtrim(strtolower(trim($url)), '/');
    }

    private function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @param array<int, array<string, mixed>> $hypotheses
     * @param array<int, int> $sourceIds
     * @param array<int, string> $warnings
     * @return array<string, mixed>
     */
    private function result(
        bool $structured,
        string $mode,
        string $answer,
        array $hypotheses,
        ?string $nextQuestion,
        ?string $action,
        bool $resolved,
        ?string $cause,
        array $sourceIds,
        array $warnings
    ): array {
        return [
            'structured' => $structured,
            'mode' => $mode,
            'answer' => $answer,
            'hypotheses' => $hypotheses,
            'next_question' => $nextQuestion,
            'recommended_action' => $action,
            'resolved' => $resolved,
            'confirmed_cause' => $cause,
            'source_ids' => $sourceIds,
            'warnings' => array_values(array_unique($warnings)),
            'escalation_recommended' => false,
            'escalation_reason' => null,
            'source_conflicts' => [],
        ];
    }

    /**
     * Divergências entre fontes: só ids reais, no mínimo 2 fontes distintas.
     *
     * @param mixed $items
     * @param array<int, string> $trustedUrls
     * @param array<int, string> $warnings
     * @return array<int, array{source_id: int, claim: string}>
     */
    private function validateConflicts($items, int $sourceCount, array $trustedUrls, array &$warnings): array
    {
        if (!is_array($items)) {
            return [];
        }

        $out = [];

        foreach (array_slice($items, 0, 6) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $ids = $this->validIds([$item['source_id'] ?? null], $sourceCount, $warnings);
            $claim = $this->optionalText($item['claim'] ?? null, $sourceCount, $trustedUrls, $warnings);

            if ($ids !== [] && $claim !== null && !isset($out[$ids[0]])) {
                $out[$ids[0]] = ['source_id' => $ids[0], 'claim' => mb_substr($claim, 0, 300)];
            }
        }

        return count($out) >= 2 ? array_values($out) : [];
    }
}
