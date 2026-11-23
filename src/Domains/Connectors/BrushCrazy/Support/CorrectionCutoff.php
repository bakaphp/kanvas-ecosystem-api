<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Support;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

/**
 * The timezone-correction boundary (see EventInstant) has to be one fixed instant for the whole
 * migration. Falling back to now() would give every webhook its own boundary, and a row edited
 * twice could flip between corrected and as-stored semantics.
 */
final class CorrectionCutoff
{
    /**
     * Pins the boundary if it is not pinned yet and returns whatever is pinned. A different
     * $requested on a later run is refused rather than applied: rows already written under the old
     * boundary would not be re-evaluated.
     */
    public static function pin(AppInterface $app, ?Carbon $requested = null): Carbon
    {
        $pinned = self::stored($app);

        if ($pinned === null) {
            $pinned = ($requested ?? Carbon::now('UTC'))->copy()->utc();
            $app->set(ConfigurationEnum::BRUSHCRAZY_TZ_CORRECTION_CUTOFF->value, $pinned->toIso8601String());

            return $pinned;
        }

        if ($requested !== null && ! $requested->equalTo($pinned)) {
            throw new ValidationException(
                'BrushCrazy timezone correction cutoff is already pinned at ' . $pinned->toIso8601String()
                . '; moving it would leave rows written under the old boundary with the wrong semantics.'
            );
        }

        return $pinned;
    }

    public static function stored(AppInterface $app): ?Carbon
    {
        return self::instantSetting($app, ConfigurationEnum::BRUSHCRAZY_TZ_CORRECTION_CUTOFF);
    }

    /**
     * Shared by every BrushCrazy setting that holds an instant (this cutoff, BRUSHCRAZY_CUTOVER_AT),
     * so the import and the mirror read them identically.
     */
    public static function instantSetting(AppInterface $app, ConfigurationEnum $key): ?Carbon
    {
        return self::parse($app->get($key->value));
    }

    public static function cutoverAt(AppInterface $app): ?Carbon
    {
        return self::instantSetting($app, ConfigurationEnum::BRUSHCRAZY_CUTOVER_AT);
    }

    /** See ConfigurationEnum::BRUSHCRAZY_CUTOVER_AT: an app-wide kill switch, set only by hand. */
    public static function isPastCutover(AppInterface $app, ?Carbon $now = null): bool
    {
        $cutoverAt = self::cutoverAt($app);

        return $cutoverAt !== null && ($now ?? Carbon::now('UTC'))->greaterThanOrEqualTo($cutoverAt);
    }

    public static function parse(mixed $value): ?Carbon
    {
        return $value !== null && $value !== '' ? Carbon::parse((string) $value)->utc() : null;
    }
}
