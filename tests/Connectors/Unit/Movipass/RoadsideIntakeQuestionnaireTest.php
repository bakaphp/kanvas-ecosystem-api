<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\Movipass;

use Kanvas\Connectors\Movipass\Actions\ValidateRoadsideIntakeAction;
use Kanvas\Connectors\Movipass\Enums\RoadsideServiceTypeEnum;
use Kanvas\Connectors\Movipass\Support\RoadsideIntakeField;
use Kanvas\Connectors\Movipass\Support\RoadsideIntakeQuestionnaire;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

final class RoadsideIntakeQuestionnaireTest extends TestCase
{
    public function testEveryServiceHasItsOwnQuestionsOnTopOfTheGeneralBlock(): void
    {
        $generalKeys = array_map(
            static fn (RoadsideIntakeField $field): string => $field->key,
            RoadsideIntakeQuestionnaire::general(),
        );

        foreach (RoadsideServiceTypeEnum::cases() as $service) {
            $serviceFields = RoadsideIntakeQuestionnaire::forService($service);

            $this->assertNotEmpty($serviceFields, $service->value . ' has no service-specific questions');

            $full = array_map(
                static fn (RoadsideIntakeField $field): string => $field->key,
                RoadsideIntakeQuestionnaire::fullFor($service),
            );

            $this->assertSame($generalKeys, array_slice($full, 0, count($generalKeys)));
            $this->assertCount(count($full), array_unique($full), $service->value . ' has duplicate question keys');
        }
    }

    public function testOnlyTireChangeAndAccidentTowDemandPhotos(): void
    {
        $requiring = array_values(array_filter(
            RoadsideServiceTypeEnum::cases(),
            static fn (RoadsideServiceTypeEnum $service): bool => RoadsideIntakeQuestionnaire::requiresPhotos($service),
        ));

        $this->assertSame(
            [RoadsideServiceTypeEnum::TIRE_CHANGE, RoadsideServiceTypeEnum::ACCIDENT_TOW],
            $requiring,
        );
    }

    public function testServiceTypeResolvesFromEnglishLabelsAndOperatorAliases(): void
    {
        $this->assertSame(RoadsideServiceTypeEnum::LIGHT_TOW, RoadsideServiceTypeEnum::tryFromLabel('Light tow'));
        $this->assertSame(RoadsideServiceTypeEnum::LIGHT_TOW, RoadsideServiceTypeEnum::tryFromLabel('light_tow'));
        $this->assertSame(RoadsideServiceTypeEnum::LIGHT_TOW, RoadsideServiceTypeEnum::tryFromLabel('  Grúa Liviana '));
        $this->assertSame(RoadsideServiceTypeEnum::TIRE_CHANGE, RoadsideServiceTypeEnum::tryFromLabel('Tire change'));
        // The legacy free-text label real cases were created with.
        $this->assertSame(
            RoadsideServiceTypeEnum::TIRE_CHANGE,
            RoadsideServiceTypeEnum::tryFromLabel('Cambio de neumático'),
        );
        $this->assertSame(RoadsideServiceTypeEnum::JUMP_START, RoadsideServiceTypeEnum::tryFromLabel('battery'));
        $this->assertNull(RoadsideServiceTypeEnum::tryFromLabel('car wash'));
        $this->assertNull(RoadsideServiceTypeEnum::tryFromLabel(null));
        $this->assertNull(RoadsideServiceTypeEnum::tryFromLabel(''));
    }

    public function testValidationReportsEveryMissingRequiredQuestionAtOnce(): void
    {
        try {
            new ValidateRoadsideIntakeAction(RoadsideServiceTypeEnum::LOCKSMITH, [
                'contact_name' => 'Ana Ramirez',
            ])->execute();

            $this->fail('Expected the incomplete intake to be rejected');
        } catch (ValidationException $exception) {
            $message = $exception->getMessage();

            $this->assertStringContainsString('Locksmith', $message);
            $this->assertStringContainsString('Contact phone number', $message);
            $this->assertStringContainsString('Where are the keys?', $message);
            $this->assertStringNotContainsString('Contact name', $message);
        }
    }

    public function testAnswersAreCastToTheirDeclaredTypes(): void
    {
        $answers = new ValidateRoadsideIntakeAction(
            RoadsideServiceTypeEnum::FUEL_DELIVERY,
            [...$this->generalAnswers(), ...[
                'fuel_type' => ' regular ',
                'fuel_gallons' => '2',
                'payment_method' => 'CASH',
            ]],
        )->execute();

        $this->assertSame('regular', $answers['fuel_type']);
        $this->assertSame(2, $answers['fuel_gallons']);
        $this->assertSame('cash', $answers['payment_method']);
        $this->assertSame(2019, $answers['vehicle_year']);
        $this->assertStringContainsString('2026', $answers['incident_time']);
    }

    public function testBooleansAcceptTheAnswersOperatorsActuallyTypeInEitherLanguage(): void
    {
        $answers = new ValidateRoadsideIntakeAction(
            RoadsideServiceTypeEnum::MOTORCYCLE_TOW,
            [...$this->generalAnswers(), ...[
                'failure_description' => 'Will not start',
                'engine_displacement' => '150cc',
                'has_top_case' => 'sí',
                'accompanies_transfer' => 'no',
            ]],
        )->execute();

        $this->assertTrue($answers['has_top_case']);
        $this->assertFalse($answers['accompanies_transfer']);
    }

    public function testFalseIsAnAnswerNotAMissingField(): void
    {
        $answers = new ValidateRoadsideIntakeAction(
            RoadsideServiceTypeEnum::JUMP_START,
            [...$this->generalAnswers(), ...[
                'lights_turn_on' => false,
                'air_conditioning_works' => false,
                'stalled_context' => 'parked',
                'single_battery' => false,
            ]],
        )->execute();

        $this->assertFalse($answers['lights_turn_on']);
        $this->assertFalse($answers['single_battery']);
    }

    public function testDependentQuestionIsOnlyDemandedWhenItsParentAnswersTrue(): void
    {
        $base = [...$this->generalAnswers(), ...[
            'lights_turn_on' => true,
            'air_conditioning_works' => true,
            'stalled_context' => 'while_driving',
            'single_battery' => true,
        ]];

        // Not an armored vehicle: no armor level to ask about.
        $answers = new ValidateRoadsideIntakeAction(
            RoadsideServiceTypeEnum::JUMP_START,
            [...$base, 'is_armored' => false],
        )->execute();

        $this->assertFalse($answers['is_armored']);
        $this->assertArrayNotHasKey('armor_level', $answers);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/Armor level/');
        new ValidateRoadsideIntakeAction(
            RoadsideServiceTypeEnum::JUMP_START,
            [...$base, 'is_armored' => true],
        )->execute();
    }

    public function testChoiceRejectsAValueOutsideItsOptions(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/Payment.*transfer, cash/');

        new ValidateRoadsideIntakeAction(
            RoadsideServiceTypeEnum::FUEL_DELIVERY,
            [...$this->generalAnswers(), ...[
                'fuel_type' => 'premium',
                'fuel_gallons' => 1,
                'payment_method' => 'crypto',
            ]],
        )->execute();
    }

    public function testUnknownAnswersArePreservedRatherThanDropped(): void
    {
        $answers = new ValidateRoadsideIntakeAction(
            RoadsideServiceTypeEnum::LOCKSMITH,
            [...$this->generalAnswers(), ...[
                'keys_location' => 'Locked inside the vehicle',
                'incident_kind' => 'lost_keys',
                'vehicle_engine_state' => 'off',
                'operator_extra_note' => 'Client is in an underground parking garage',
            ]],
        )->execute();

        $this->assertSame('Client is in an underground parking garage', $answers['operator_extra_note']);
    }

    private function generalAnswers(): array
    {
        return [
            'contact_name' => 'Ana Ramirez',
            'contact_phone' => '77003300',
            'vehicle_plate' => 'P123456',
            'vehicle_make' => 'Toyota',
            'vehicle_model' => 'Hilux',
            'vehicle_year' => '2019',
            'vehicle_color' => 'White',
            'incident_time' => '2026-09-09 14:30:00',
            'location_reference' => 'Main boulevard, in front of the shopping mall',
            'incident_description' => 'The vehicle will not start',
        ];
    }
}
