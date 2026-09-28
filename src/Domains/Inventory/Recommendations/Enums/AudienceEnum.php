<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Enums;

/**
 * Who a product is for. Owned here rather than in the ProductEnrichment connector
 * so discovery does not depend on a connector — the connector's `audience` facet
 * vocabulary must list these same values, exactly as with SearchFieldEnum.
 *
 * These are a filter, not a ranking signal: an embedding reads "regalo para
 * hombre" and a blurb saying "para mujeres" as SIMILAR, because both are about
 * gifting to a person. Vector search has no notion of contradiction, so the one
 * axis a shopper is never wrong about has to be enforced outside it.
 */
enum AudienceEnum: string
{
    case MALE = 'male';
    case FEMALE = 'female';
    case UNISEX = 'unisex';
    case KIDS = 'kids';
    case BABY = 'baby';
    case TEEN = 'teen';
    case SENIOR = 'senior';

    /**
     * Indexed in place of an empty array. Typesense has no dependable "this array
     * is empty" filter, so an un-enriched product carries a value that says so and
     * the filter admits it by name.
     */
    case UNKNOWN = 'unknown';

    /**
     * Ride along with whatever the shopper asked for. Without UNISEX a query for a
     * man loses every gender-neutral product, which is most of a catalog; without
     * UNKNOWN it loses everything not yet enriched.
     *
     * @return list<self>
     */
    public static function alwaysIncluded(): array
    {
        return [self::UNISEX, self::UNKNOWN];
    }

    /**
     * @return list<self>
     */
    public static function matchable(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $case): bool => ! in_array($case, self::alwaysIncluded(), true),
        ));
    }

    public function lexiconBucket(): string
    {
        return 'audience_' . $this->value;
    }
}
