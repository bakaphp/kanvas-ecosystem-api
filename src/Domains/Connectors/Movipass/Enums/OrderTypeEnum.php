<?php

namespace Kanvas\Connectors\Movipass\Enums;

enum OrderTypeEnum: string
{
    case MOVIPASS = 'movipass';
    case PASO_RAPIDO = 'paso_rapido';
    case IMPOUND_LOT = 'impound_lot';
    case ROADSIDE_ASSISTANCE = 'roadside_assistance';

    /**
     * Entry / exit / still-open status slugs that turn an order stream into a turnover report.
     *
     * Parking and impound statuses are stored with their raw underscore value (`in_transit`,
     * `released_from_lot`) while roadside statuses were seeded through Str::slug and are dashed
     * (`service-completed`). Mixing the two up silently returns zero rows, so each case reaches
     * for whichever form its own rows actually carry.
     *
     * Paso Rápido has no dwell time — a tag recharge opens and closes in the same event — so it
     * reports through the payment tool instead and has no turnover shape here.
     *
     * @return array{initial: string[], final: string[], current: string[]}|null
     */
    public function turnoverStates(): ?array
    {
        return match ($this) {
            self::MOVIPASS => [
                'initial' => [MovipassOrderStatusEnum::ACTIVE->slug()],
                'final' => [MovipassOrderStatusEnum::COMPLETED->value],
                'current' => [
                    MovipassOrderStatusEnum::PAID->value,
                    MovipassOrderStatusEnum::ACTIVE->slug(),
                ],
            ],
            self::IMPOUND_LOT => [
                'initial' => [MovipassOrderStatusEnum::IN_TRANSIT->value],
                'final' => [
                    MovipassOrderStatusEnum::RELEASED->value,
                    MovipassOrderStatusEnum::CANCELLED->value,
                ],
                'current' => [
                    MovipassOrderStatusEnum::DELIVERED->value,
                    MovipassOrderStatusEnum::PAID->value,
                ],
            ],
            self::ROADSIDE_ASSISTANCE => [
                'initial' => [MovipassOrderStatusEnum::AWAITING_OPERATOR->slug()],
                'final' => [
                    MovipassOrderStatusEnum::SERVICE_COMPLETED->slug(),
                    MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->slug(),
                    MovipassOrderStatusEnum::SERVICE_CANCELLED->slug(),
                ],
                'current' => [
                    MovipassOrderStatusEnum::PROVIDER_ASSIGNED->slug(),
                    MovipassOrderStatusEnum::DISPATCHED->slug(),
                    MovipassOrderStatusEnum::ON_SITE->slug(),
                    MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->slug(),
                ],
            ],
            self::PASO_RAPIDO => null,
        };
    }

    /**
     * @return string[]
     */
    public static function turnoverCapableValues(): array
    {
        return array_values(array_map(
            fn (self $case): string => $case->value,
            array_filter(self::cases(), fn (self $case): bool => $case->turnoverStates() !== null),
        ));
    }
}
