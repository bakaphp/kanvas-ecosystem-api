<?php

declare(strict_types=1);

namespace Tests\Connectors\Movipass\Concerns;

use Kanvas\Connectors\Movipass\Enums\RoadsideServiceTypeEnum;

/**
 * Answers that pass validation and every discard rule, so a test only overrides the one answer it
 * is about.
 */
trait BuildsRoadsideIntake
{
    protected function proceedingIntake(RoadsideServiceTypeEnum $service, array $overrides = []): array
    {
        $general = [
            'contact_name' => 'Ana Ramirez',
            'contact_phone' => '77003300',
            'vehicle_plate' => 'P123456',
            'vehicle_make' => 'Toyota',
            'vehicle_model' => 'Hilux',
            'vehicle_year' => '2019',
            'vehicle_color' => 'White',
            'incident_time' => '2026-10-20 09:45',
            'location_reference' => 'In front of the gas station',
            'incident_description' => 'The vehicle does not start',
        ];

        $specific = match ($service) {
            RoadsideServiceTypeEnum::JUMP_START => [
                'is_electric' => false,
                'is_heavy_vehicle' => false,
                'lights_turn_on' => true,
                'air_conditioning_works' => false,
                'stalled_context' => 'parked',
                'single_battery' => true,
            ],
            RoadsideServiceTypeEnum::FUEL_DELIVERY => [
                'fuel_type' => 'regular',
                'fuel_gallons' => 2,
                'confirms_fuel_type' => true,
                'authorizes_fuel_charge' => true,
            ],
            RoadsideServiceTypeEnum::LIGHT_TOW => [
                'failure_type' => 'mechanical',
                'tow_hook' => true,
                'authorizes_scissor_lift' => true,
                'accompanies_transfer' => false,
                'can_shift_to_neutral' => true,
                'handbrake_type' => 'manual',
                'transmission_type' => 'automatic',
                'workshop_confirmed' => true,
            ],
            RoadsideServiceTypeEnum::DESIGNATED_DRIVER => [
                'request_reason' => 'fatigue',
                'requested_service_time' => '2026-10-20 22:00',
                'pickup_location' => 'Restaurant parking lot',
                'dropoff_location' => 'Home',
                'authorizes_driver' => true,
            ],
            RoadsideServiceTypeEnum::TIRE_CHANGE => [
                'damaged_tire_position' => 'front_left',
                'spare_tire_in_good_condition' => true,
                'hit_pothole' => true,
                'tire_condition' => 'deflated',
                'lug_nut_type' => 'conventional',
            ],
            RoadsideServiceTypeEnum::ACCIDENT_TOW => [
                'police_present' => true,
                'police_report_exists' => true,
                'injuries_reported' => false,
                'fatalities_reported' => false,
                'third_parties_involved' => false,
                'authorization_to_move_vehicle' => true,
                'special_maneuvers_required' => false,
                'can_shift_to_neutral' => true,
            ],
            RoadsideServiceTypeEnum::MOTORCYCLE_TOW => [
                'failure_type' => 'mechanical',
                'engine_displacement' => 150,
                'has_top_case' => false,
                'accompanies_transfer' => false,
            ],
            RoadsideServiceTypeEnum::LOCKSMITH => [
                'keys_location' => 'cabin',
                'only_opening_required' => true,
                'owner_declaration' => true,
            ],
        };

        return [...$general, ...$specific, ...$overrides];
    }
}
