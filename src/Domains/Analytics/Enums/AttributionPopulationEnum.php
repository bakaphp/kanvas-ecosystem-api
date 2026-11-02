<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Enums;

/**
 * Which leads an attribution report is about. SOLD is a sales question ("of what we sold, what
 * helped"); OPEN and ALL are cohort questions ("of the leads we got, which were touched, and did
 * the touched ones close more") and are keyed on the lead's creation date because an open lead
 * has no close date to key on.
 */
enum AttributionPopulationEnum: string
{
    case SOLD = 'sold';
    case OPEN = 'open';
    case ALL = 'all';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function dateColumn(): string
    {
        return $this === self::SOLD ? 'closed_at' : 'created_at';
    }
}
