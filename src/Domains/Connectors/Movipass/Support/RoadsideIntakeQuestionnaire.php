<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Support;

use Kanvas\Connectors\Movipass\Enums\RoadsideServiceTypeEnum;

/**
 * The operator intake script: every case starts with the same general question block, then branches
 * into one service-specific block. Both live here so the app can render the questions and the API
 * can validate the answers from a single definition instead of the two drifting apart.
 */
final class RoadsideIntakeQuestionnaire
{
    /**
     * @return array<int, RoadsideIntakeField>
     */
    public static function general(): array
    {
        return [
            RoadsideIntakeField::text('contact_name', 'Contact name'),
            RoadsideIntakeField::text('contact_phone', 'Contact phone number'),
            RoadsideIntakeField::text('vehicle_plate', 'Vehicle plate'),
            RoadsideIntakeField::text('vehicle_make', 'Make'),
            RoadsideIntakeField::text('vehicle_model', 'Model'),
            RoadsideIntakeField::number('vehicle_year', 'Year'),
            RoadsideIntakeField::text('vehicle_color', 'Color'),
            RoadsideIntakeField::dateTime('incident_time', 'Time of the incident'),
            RoadsideIntakeField::text('location_reference', 'Exact location and landmarks'),
            RoadsideIntakeField::text('incident_description', 'What happened'),
        ];
    }

    /**
     * @return array<int, RoadsideIntakeField>
     */
    public static function forService(RoadsideServiceTypeEnum $service): array
    {
        return match ($service) {
            RoadsideServiceTypeEnum::JUMP_START => [
                RoadsideIntakeField::boolean('lights_turn_on', 'Do the dashboard lights turn on?'),
                RoadsideIntakeField::boolean('air_conditioning_works', 'Does the air conditioning work?'),
                RoadsideIntakeField::choice('stalled_context', 'Was it parked or did it stall while driving?', [
                    'parked',
                    'while_driving',
                ]),
                RoadsideIntakeField::boolean('single_battery', 'Does it have a single battery?'),
                RoadsideIntakeField::boolean('is_armored', 'Is it armored? (trucks and SUVs)', required: false),
                RoadsideIntakeField::text('armor_level', 'Armor level')->dependentOn('is_armored'),
            ],
            RoadsideServiceTypeEnum::FUEL_DELIVERY => [
                RoadsideIntakeField::text('fuel_type', 'Fuel type'),
                RoadsideIntakeField::number(
                    'fuel_gallons',
                    'How many gallons',
                    hint: 'Two gallons maximum recommended',
                ),
                RoadsideIntakeField::text('preferred_gas_station', 'Preferred gas station', required: false),
                RoadsideIntakeField::choice('payment_method', 'Payment', ['transfer', 'cash']),
            ],
            RoadsideServiceTypeEnum::LIGHT_TOW => [
                RoadsideIntakeField::text('failure_description', 'Failure'),
                RoadsideIntakeField::boolean(
                    'hit_water_or_pothole',
                    'Did it drive through water, a damaged road or a pothole?',
                ),
                RoadsideIntakeField::boolean('tow_hook', 'Tow hook'),
                RoadsideIntakeField::boolean('authorizes_scissor_lift', 'Authorizes lifting by scissor jack'),
                RoadsideIntakeField::boolean('accompanies_transfer', 'Riding along with the tow'),
                RoadsideIntakeField::number('accompanying_passengers', 'How many passengers')
                    ->dependentOn('accompanies_transfer'),
                RoadsideIntakeField::boolean('can_shift_to_neutral', 'Can it be shifted into neutral?'),
                RoadsideIntakeField::choice('handbrake_type', 'Handbrake', ['electric', 'manual']),
                RoadsideIntakeField::choice('transmission_type', 'Transmission', ['standard', 'automatic']),
                RoadsideIntakeField::boolean('is_armored', 'Is it armored? (trucks and SUVs)', required: false),
                RoadsideIntakeField::text('armor_level', 'Armor level')->dependentOn('is_armored'),
                RoadsideIntakeField::boolean('workshop_confirmed', 'Has the workshop already been confirmed?'),
            ],
            RoadsideServiceTypeEnum::DESIGNATED_DRIVER => [
                RoadsideIntakeField::text('request_reason', 'Reason for the request'),
                RoadsideIntakeField::dateTime('requested_service_time', 'Requested service time'),
                RoadsideIntakeField::text('pickup_location', 'Pickup location'),
                RoadsideIntakeField::text('dropoff_location', 'Drop-off location'),
            ],
            RoadsideServiceTypeEnum::TIRE_CHANGE => [
                RoadsideIntakeField::text('damaged_tire_position', 'Which tire is damaged'),
                RoadsideIntakeField::boolean('spare_tire_in_good_condition', 'Is the spare tire in good condition?'),
                RoadsideIntakeField::boolean('hit_pothole', 'Did it hit a pothole?'),
                RoadsideIntakeField::choice('tire_condition', 'Burst or deflated?', ['burst', 'deflated']),
                RoadsideIntakeField::boolean('has_security_lug_nut', 'Security lug nut'),
                RoadsideIntakeField::choice('lug_nut_type', 'Lug nuts', ['conventional', 'special']),
                RoadsideIntakeField::boolean('is_armored', 'Is the vehicle armored?', required: false),
                RoadsideIntakeField::text('armor_level', 'Armor level')->dependentOn('is_armored'),
            ],
            RoadsideServiceTypeEnum::ACCIDENT_TOW => [
                RoadsideIntakeField::boolean('police_present', 'Is the police on site?'),
                RoadsideIntakeField::text('police_report_number', 'Police report number')
                    ->dependentOn('police_present'),
                RoadsideIntakeField::boolean('injuries_reported', 'Injuries'),
                RoadsideIntakeField::boolean('fatalities_reported', 'Fatalities'),
                RoadsideIntakeField::boolean('third_parties_involved', 'Third parties involved'),
                RoadsideIntakeField::boolean('authorization_to_move_vehicle', 'Authorization to move the vehicle'),
                RoadsideIntakeField::text('maneuvers_required', 'Maneuvers required', required: false),
                RoadsideIntakeField::boolean('can_shift_to_neutral', 'Can it be shifted into neutral?'),
            ],
            RoadsideServiceTypeEnum::MOTORCYCLE_TOW => [
                RoadsideIntakeField::text('failure_description', 'Failure'),
                RoadsideIntakeField::text('engine_displacement', 'Engine displacement'),
                RoadsideIntakeField::boolean('has_top_case', 'Top case'),
                RoadsideIntakeField::boolean('accompanies_transfer', 'Riding along with the tow'),
            ],
            RoadsideServiceTypeEnum::LOCKSMITH => [
                RoadsideIntakeField::text('keys_location', 'Where are the keys?'),
                RoadsideIntakeField::choice('incident_kind', 'Vandalism or lost keys?', [
                    'vandalism',
                    'lost_keys',
                ]),
                RoadsideIntakeField::choice('vehicle_engine_state', 'Is the engine off or running?', [
                    'off',
                    'running',
                ]),
            ],
        };
    }

    /**
     * @return array<int, RoadsideIntakeField>
     */
    public static function fullFor(RoadsideServiceTypeEnum $service): array
    {
        return [...self::general(), ...self::forService($service)];
    }

    /**
     * @return array<string, RoadsideIntakeField>
     */
    public static function keyedFor(RoadsideServiceTypeEnum $service): array
    {
        $keyed = [];

        foreach (self::fullFor($service) as $field) {
            $keyed[$field->key] = $field;
        }

        return $keyed;
    }

    /**
     * Photos are required on the two services where the operator cannot size the job from the phone
     * call alone: the lug-nut/rim type on a tire change, and the damage severity on an accident tow.
     */
    public static function requiresPhotos(RoadsideServiceTypeEnum $service): bool
    {
        return in_array($service, [
            RoadsideServiceTypeEnum::TIRE_CHANGE,
            RoadsideServiceTypeEnum::ACCIDENT_TOW,
        ], true);
    }
}
