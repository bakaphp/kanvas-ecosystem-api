<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Support;

use Kanvas\Connectors\Movipass\Enums\RoadsideEvidencePhaseEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideServiceTypeEnum;

/**
 * Spec v6.0 §9.3: how many camera photos each service needs before and after the work, and the
 * operative comment every close requires. Evidence is append-only, so a replaced photo still counts
 * once and a correction is simply one more record.
 */
final class RoadsideEvidenceRules
{
    public static function requiredPhotos(?RoadsideServiceTypeEnum $service): int
    {
        return match ($service) {
            RoadsideServiceTypeEnum::LIGHT_TOW,
            RoadsideServiceTypeEnum::ACCIDENT_TOW,
            RoadsideServiceTypeEnum::MOTORCYCLE_TOW,
            RoadsideServiceTypeEnum::DESIGNATED_DRIVER => 4,
            default => 2,
        };
    }

    public static function requiredForCase(array $assistanceCase): int
    {
        return self::requiredPhotos(RoadsideServiceTypeEnum::tryFrom((string) ($assistanceCase['service_type'] ?? '')));
    }

    public static function summary(array $assistanceCase): array
    {
        return [
            'required_per_phase' => self::requiredForCase($assistanceCase),
            'before' => self::countFor($assistanceCase, RoadsideEvidencePhaseEnum::BEFORE),
            'after' => self::countFor($assistanceCase, RoadsideEvidencePhaseEnum::AFTER),
            'has_comment' => self::hasOperativeComment($assistanceCase),
            'missing_for_closing' => self::missingForClosing($assistanceCase),
        ];
    }

    public static function countFor(array $assistanceCase, RoadsideEvidencePhaseEnum $phase): int
    {
        return count(array_filter(
            (array) ($assistanceCase['evidence'] ?? []),
            static fn (mixed $item) => is_array($item) && ($item['phase'] ?? null) === $phase->value,
        ));
    }

    public static function hasOperativeComment(array $assistanceCase): bool
    {
        $comments = (array) ($assistanceCase['operative_comments'] ?? []);
        $latest = end($comments);

        return is_array($latest) && trim((string) ($latest['comment'] ?? '')) !== '';
    }

    /**
     * @return array<int, string> what is still missing, empty when the case may close
     */
    public static function missingForClosing(array $assistanceCase): array
    {
        $required = self::requiredForCase($assistanceCase);
        $missing = [];

        foreach (RoadsideEvidencePhaseEnum::cases() as $phase) {
            $count = self::countFor($assistanceCase, $phase);

            if ($count < $required) {
                $missing[] = sprintf('%d of %d %s photos', $count, $required, $phase->value);
            }
        }

        if (! self::hasOperativeComment($assistanceCase)) {
            $missing[] = 'the operative comment';
        }

        return $missing;
    }
}
