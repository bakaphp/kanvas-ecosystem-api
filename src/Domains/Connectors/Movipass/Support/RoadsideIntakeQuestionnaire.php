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
        // Spec v6.0 §7. The knockout and cabin-review rules for these answers live in
        // RoadsideIntakeRules; this class only says what is asked.
        return match ($service) {
            RoadsideServiceTypeEnum::JUMP_START => [
                RoadsideIntakeField::boolean('is_electric', 'Is the vehicle electric?'),
                RoadsideIntakeField::boolean('is_heavy_vehicle', 'Is it a heavy vehicle or truck?'),
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
                RoadsideIntakeField::choice('fuel_type', 'Fuel type', ['regular', 'super', 'diesel']),
                RoadsideIntakeField::number(
                    'fuel_gallons',
                    'How many gallons',
                    hint: 'Two gallons maximum recommended',
                ),
                RoadsideIntakeField::boolean('confirms_fuel_type', 'I confirm the selected fuel matches my vehicle'),
                RoadsideIntakeField::boolean(
                    'authorizes_fuel_charge',
                    'I authorize the fuel cost to be added to the open order',
                ),
            ],
            RoadsideServiceTypeEnum::LIGHT_TOW => [
                RoadsideIntakeField::choice('failure_type', 'What failure does the vehicle have?', [
                    'mechanical',
                    'electrical',
                    'immobilized',
                    'other',
                ]),
                RoadsideIntakeField::text('failure_description', 'Describe the failure')
                    ->dependentOn('failure_type', 'other'),
                RoadsideIntakeField::boolean('tow_hook', 'Tow hook'),
                RoadsideIntakeField::boolean('authorizes_scissor_lift', 'Authorizes lifting by scissor jack'),
                RoadsideIntakeField::boolean('accompanies_transfer', 'Riding along with the tow'),
                RoadsideIntakeField::choice('accompanying_passengers', 'How many passengers', ['1', '2', '3+'])
                    ->dependentOn('accompanies_transfer'),
                RoadsideIntakeField::boolean('can_shift_to_neutral', 'Can it be shifted into neutral?'),
                RoadsideIntakeField::choice('handbrake_type', 'Handbrake', ['electric', 'manual']),
                RoadsideIntakeField::choice('transmission_type', 'Transmission', ['standard', 'automatic']),
                RoadsideIntakeField::boolean('is_armored', 'Is it armored? (trucks and SUVs)', required: false),
                RoadsideIntakeField::text('armor_level', 'Armor level')->dependentOn('is_armored'),
                RoadsideIntakeField::boolean('workshop_confirmed', 'Has the destination workshop been confirmed?'),
            ],
            RoadsideServiceTypeEnum::DESIGNATED_DRIVER => [
                RoadsideIntakeField::choice('request_reason', 'Reason for the request', [
                    'alcohol',
                    'fatigue',
                    'other',
                ]),
                RoadsideIntakeField::text('request_reason_detail', 'Describe the reason')
                    ->dependentOn('request_reason', 'other'),
                RoadsideIntakeField::dateTime('requested_service_time', 'Requested service time'),
                RoadsideIntakeField::text('pickup_location', 'Pickup location'),
                RoadsideIntakeField::text('dropoff_location', 'Final destination'),
                RoadsideIntakeField::boolean('authorizes_driver', 'I authorize the designated driver to drive my vehicle'),
                RoadsideIntakeField::text('additional_information', 'Anything else we should consider', required: false),
            ],
            RoadsideServiceTypeEnum::TIRE_CHANGE => [
                RoadsideIntakeField::choice('damaged_tire_position', 'Which tire is damaged', [
                    'front_left',
                    'front_right',
                    'rear_left',
                    'rear_right',
                ]),
                RoadsideIntakeField::boolean('spare_tire_in_good_condition', 'Is the spare tire fit to be installed and used?'),
                RoadsideIntakeField::boolean('hit_pothole', 'Did it hit a pothole or a damaged road?'),
                RoadsideIntakeField::choice('tire_condition', 'Burst or deflated?', ['burst', 'deflated']),
                RoadsideIntakeField::boolean('has_security_lug_nut', 'Security lug nut', required: false),
                RoadsideIntakeField::choice('lug_nut_type', 'Lug nuts', ['conventional', 'special']),
                RoadsideIntakeField::boolean('is_armored', 'Is the vehicle armored?', required: false),
                RoadsideIntakeField::text('armor_level', 'Armor level')->dependentOn('is_armored'),
            ],
            RoadsideServiceTypeEnum::ACCIDENT_TOW => [
                RoadsideIntakeField::boolean('police_present', 'Is the police on site?'),
                RoadsideIntakeField::boolean('police_report_exists', 'Is there already a police report?'),
                RoadsideIntakeField::text('police_report_number', 'Police report number', required: false),
                RoadsideIntakeField::boolean('injuries_reported', 'Injuries'),
                RoadsideIntakeField::boolean('fatalities_reported', 'Fatalities'),
                RoadsideIntakeField::boolean('third_parties_involved', 'Third parties involved'),
                RoadsideIntakeField::boolean('authorization_to_move_vehicle', 'Authorization to move the vehicle'),
                RoadsideIntakeField::boolean('special_maneuvers_required', 'Are special maneuvers required?'),
                RoadsideIntakeField::text('maneuvers_required', 'Maneuvers required', required: false),
                RoadsideIntakeField::boolean('can_shift_to_neutral', 'Can it be shifted into neutral?'),
            ],
            RoadsideServiceTypeEnum::MOTORCYCLE_TOW => [
                RoadsideIntakeField::choice('failure_type', 'What failure does the motorcycle have?', [
                    'mechanical',
                    'electrical',
                    'cannot_ride',
                    'other',
                ]),
                RoadsideIntakeField::text('failure_description', 'Describe the failure')
                    ->dependentOn('failure_type', 'other'),
                RoadsideIntakeField::number('engine_displacement', 'Engine displacement (cc)'),
                RoadsideIntakeField::boolean('has_top_case', 'Top case'),
                RoadsideIntakeField::boolean('accompanies_transfer', 'Riding along with the tow'),
            ],
            RoadsideServiceTypeEnum::LOCKSMITH => [
                RoadsideIntakeField::choice('keys_location', 'Were the keys left in the cabin or the trunk?', [
                    'cabin',
                    'trunk',
                ]),
                RoadsideIntakeField::boolean('only_opening_required', 'Is only opening the vehicle required?'),
                RoadsideIntakeField::boolean(
                    'owner_declaration',
                    'I declare I am the owner, an authorized user, or authorized to request the opening',
                ),
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
