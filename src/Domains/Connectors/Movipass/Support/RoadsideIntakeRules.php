<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Support;

use Kanvas\Connectors\Movipass\Actions\ValidateRoadsideIntakeAction;
use Kanvas\Connectors\Movipass\Enums\RoadsideIntakeOutcomeEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideServiceTypeEnum;

/**
 * The discard rules of spec v6.0 §7: which answers stop the order (no payment), which send it to the
 * cabin before dispatch, and which service to recommend instead. The worst outcome wins; every
 * matching reason is kept so the app and the cabin can say why.
 */
final class RoadsideIntakeRules
{
    /**
     * Validates first: an incomplete questionnaire cannot be judged, and the caller gets the same
     * "missing / invalid" message the intake already produces.
     */
    public static function evaluate(RoadsideServiceTypeEnum $service, array $answers): RoadsideIntakeVerdict
    {
        $answers = new ValidateRoadsideIntakeAction($service, $answers)->execute();
        $verdict = new RoadsideIntakeVerdict();

        foreach (self::rules($service) as [$key, $when, $outcome, $reason, $recommended]) {
            if (array_key_exists($key, $answers) && $answers[$key] === $when) {
                $verdict = $verdict->with($outcome, $reason, $recommended);
            }
        }

        return $verdict;
    }

    /**
     * @return array<int, array{0: string, 1: mixed, 2: RoadsideIntakeOutcomeEnum, 3: string, 4: ?RoadsideServiceTypeEnum}>
     */
    private static function rules(RoadsideServiceTypeEnum $service): array
    {
        $stop = RoadsideIntakeOutcomeEnum::NOT_PROCEED;
        $cabin = RoadsideIntakeOutcomeEnum::CABIN_REVIEW;

        return match ($service) {
            RoadsideServiceTypeEnum::JUMP_START => [
                ['is_electric', true, $stop, 'electric_vehicle', null],
                ['is_heavy_vehicle', true, $stop, 'heavy_vehicle', null],
                ['single_battery', false, $cabin, 'multiple_batteries', null],
            ],
            RoadsideServiceTypeEnum::TIRE_CHANGE => [
                ['spare_tire_in_good_condition', false, $stop, 'spare_tire_unusable', RoadsideServiceTypeEnum::LIGHT_TOW],
            ],
            RoadsideServiceTypeEnum::FUEL_DELIVERY => [
                ['confirms_fuel_type', false, $stop, 'fuel_type_not_confirmed', null],
                ['authorizes_fuel_charge', false, $stop, 'fuel_charge_not_authorized', null],
            ],
            RoadsideServiceTypeEnum::ACCIDENT_TOW => [
                ['police_report_exists', false, $cabin, 'no_police_report', null],
                ['injuries_reported', true, $cabin, 'injuries_reported', null],
                ['fatalities_reported', true, $cabin, 'fatalities_reported', null],
                ['special_maneuvers_required', true, $cabin, 'special_maneuvers_required', null],
                ['authorization_to_move_vehicle', false, $stop, 'no_authorization_to_move', null],
            ],
            RoadsideServiceTypeEnum::LOCKSMITH => [
                ['only_opening_required', false, $stop, 'out_of_locksmith_scope', null],
                ['owner_declaration', false, $stop, 'owner_declaration_missing', null],
            ],
            RoadsideServiceTypeEnum::DESIGNATED_DRIVER => [
                ['authorizes_driver', false, $stop, 'driver_not_authorized', null],
            ],
            default => [],
        };
    }
}
