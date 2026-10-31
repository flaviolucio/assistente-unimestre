<?php

declare(strict_types=1);

namespace App\Services\Copilot;

/**
 * Taxonomia única de evidências do Copiloto (Etapa 7.1).
 *
 * O tipo de uma evidência é SEMPRE derivado da origem no banco
 * (knowledge_sources.type / internal_solutions), nunca do texto do modelo.
 * HIPÓTESE DA IA é um rótulo de raciocínio e nunca vira fonte documental.
 */
final class EvidenceType
{
    public const OFFICIAL = 'official';
    public const INTERNAL_PROCEDURE = 'internal_procedure';

    /** Valor legado (internal_solutions / message_sources antigos): lido como PROCEDIMENTO INTERNO. */
    public const LEGACY_INTERNAL = 'internal';
    public const VENDOR_SUPPORT = 'vendor_support';
    public const RESOLVED_CASE = 'resolved_case';
    public const AI_HYPOTHESIS = 'ai_hypothesis';

    /** Tipos que podem ser gravados em message_sources.source_type. */
    public const DOCUMENTARY = [
        self::OFFICIAL,
        self::INTERNAL_PROCEDURE,
        self::VENDOR_SUPPORT,
        self::RESOLVED_CASE,
    ];

    private const LABELS = [
        self::OFFICIAL => ['icon' => '📘', 'badge' => 'OFICIAL UNIMESTRE', 'label' => 'Documento oficial', 'css' => 'official'],
        self::INTERNAL_PROCEDURE => ['icon' => '🛠️', 'badge' => 'PROCEDIMENTO INTERNO', 'label' => 'Procedimento interno', 'css' => 'internal'],
        self::VENDOR_SUPPORT => ['icon' => '🧾', 'badge' => 'SUPORTE UNIMESTRE', 'label' => 'Orientação do suporte UNIMESTRE', 'css' => 'vendor'],
        self::RESOLVED_CASE => ['icon' => '🧩', 'badge' => 'CASO RESOLVIDO', 'label' => 'Caso resolvido', 'css' => 'case'],
        self::AI_HYPOTHESIS => ['icon' => '🔎', 'badge' => 'HIPÓTESE DA IA', 'label' => 'Hipótese da IA', 'css' => 'hypothesis'],
    ];

    /**
     * Tipo documental de uma fonte recuperada. Valor desconhecido nunca é
     * promovido a oficial: cai em PROCEDIMENTO INTERNO (o rótulo mais fraco
     * entre os documentais).
     *
     * @param array<string, mixed> $source
     */
    public static function fromSource(array $source): string
    {
        $type = (string) ($source['source_type'] ?? '');

        if (in_array($type, self::DOCUMENTARY, true)) {
            return $type;
        }

        if ($type === self::LEGACY_INTERNAL) {
            return self::INTERNAL_PROCEDURE;
        }

        return self::INTERNAL_PROCEDURE;
    }

    public static function badge(string $type): string
    {
        $meta = self::LABELS[$type] ?? self::LABELS[self::INTERNAL_PROCEDURE];

        return $meta['icon'] . ' ' . $meta['badge'];
    }

    public static function label(string $type): string
    {
        return (self::LABELS[$type] ?? self::LABELS[self::INTERNAL_PROCEDURE])['label'];
    }

    /** Rótulo sem emoji, usado no bloco TIPO do contexto enviado à IA. */
    public static function plainBadge(string $type): string
    {
        return (self::LABELS[$type] ?? self::LABELS[self::INTERNAL_PROCEDURE])['badge'];
    }

    public static function cssClass(string $type): string
    {
        return 'chat-badge-' . (self::LABELS[$type] ?? self::LABELS[self::INTERNAL_PROCEDURE])['css'];
    }
}
