<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Enums;

/**
 * A query describes a PERSON; a product record describes an object. Vector
 * search across that gap is weak, so the profile is written in the query's
 * register — this picks which register. Add a case when a catalog needs one.
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

    /**
     * The one paragraph that changes per catalog. Everything else in the
     * enrichment prompt — the facet rules, "ground it in the attributes", the
     * ban on filler — is mechanics and stays in code, so swapping a vertical
     * cannot take the anti-filler rules with it.
     */
    public function blurbFraming(): string
    {
        return match ($this) {
            self::GIFT => 'This is a GIFT catalog: the shopper is buying for someone else. Describe who would '
                . 'love to RECEIVE this and for what occasion — the recipient\'s personality, interests and '
                . 'life stage — in the words a shopper uses to describe a person ("mi mejor amiga, creativa, '
                . 'le encanta el café"), not the words a catalog uses for a product.',
            self::GENERIC => 'Describe who this product is for and when they would use it — the need it '
                . 'solves and the kind of person who has that need.',
        };
    }
}
