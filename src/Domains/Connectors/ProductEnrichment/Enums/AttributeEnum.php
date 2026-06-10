<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ProductEnrichment\Enums;

/**
 * The grouped facets the enrichment writes as product ATTRIBUTES — distinct
 * fields so search can do `filter_by audience:male && occasion:birthday`.
 *
 * The flat "vibe" labels (elegant, romantic, …) are NOT here — they go to the
 * Tags subsystem (HasTagsTrait), since the Tag model is a flat label set.
 */
enum AttributeEnum: string
{
    case AUDIENCE = 'audience';
    case OCCASION = 'occasion';
    case INTERESTS = 'interests';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
