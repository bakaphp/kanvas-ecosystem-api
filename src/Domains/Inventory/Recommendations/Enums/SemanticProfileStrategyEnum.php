<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Enums;

/**
 * Picks what the indexed `semantic_profile` describes.
 *
 * A customer's query describes a PERSON and a situation; a product record
 * describes an object. Vector search across that gap is weak, so the profile is
 * written in the same register as the query — the strategy decides which
 * register that is for this catalog.
 *
 * Add a case when a customer's catalog genuinely reads differently (auto parts
 * match on fitment, B2B on role and use case), not to pre-empt one.
 */
enum SemanticProfileStrategyEnum: string
{
    case GIFT = 'gift';
    case GENERIC = 'generic';

    public static function fromApp(mixed $value): self
    {
        return is_string($value)
            ? self::tryFrom($value) ?? self::GENERIC
            : self::GENERIC;
    }

    public function instruction(): string
    {
        return match ($this) {
            self::GIFT => <<<'TEXT'
            Describe who would love to RECEIVE this as a gift, and when.
            Think about the recipient's personality, interests and life stage — not the product's specs.
            Answer with ONE line, no markdown, exactly this shape:
            "ideal para: <person traits>; ocasiones: <occasions>; estilo: <style>"
            TEXT,
            self::GENERIC => <<<'TEXT'
            Describe who needs this and in what situation they reach for it.
            Focus on the need it solves and the kind of person who has that need — not the product's specs.
            Answer with ONE line, no markdown, exactly this shape:
            "resuelve: <need>; para: <person type>; contexto: <when and where it is used>"
            TEXT,
        };
    }
}
