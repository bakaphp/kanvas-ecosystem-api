<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\Movipass;

use Kanvas\Connectors\Movipass\Enums\RoadsideIntakeOutcomeEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideServiceTypeEnum;
use Kanvas\Connectors\Movipass\Support\RoadsideIntakeRules;
use Kanvas\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Connectors\Movipass\Concerns\BuildsRoadsideIntake;
use Tests\TestCase;

final class RoadsideIntakeRulesTest extends TestCase
{
    use BuildsRoadsideIntake;

    public function testEveryServiceProceedsWithItsBaselineAnswers(): void
    {
        foreach (RoadsideServiceTypeEnum::cases() as $service) {
            $verdict = RoadsideIntakeRules::evaluate($service, $this->proceedingIntake($service));

            $this->assertSame(RoadsideIntakeOutcomeEnum::PROCEED, $verdict->outcome, $service->value);
            $this->assertSame([], $verdict->reasons, $service->value);
        }
    }

    public static function knockouts(): array
    {
        return [
            'electric jump start' => [RoadsideServiceTypeEnum::JUMP_START, ['is_electric' => true], 'not_proceed', 'electric_vehicle'],
            'truck jump start' => [RoadsideServiceTypeEnum::JUMP_START, ['is_heavy_vehicle' => true], 'not_proceed', 'heavy_vehicle'],
            'two batteries' => [RoadsideServiceTypeEnum::JUMP_START, ['single_battery' => false], 'cabin_review', 'multiple_batteries'],
            'unusable spare' => [RoadsideServiceTypeEnum::TIRE_CHANGE, ['spare_tire_in_good_condition' => false], 'not_proceed', 'spare_tire_unusable'],
            'fuel not authorized' => [RoadsideServiceTypeEnum::FUEL_DELIVERY, ['authorizes_fuel_charge' => false], 'not_proceed', 'fuel_charge_not_authorized'],
            'accident without police report' => [RoadsideServiceTypeEnum::ACCIDENT_TOW, ['police_report_exists' => false], 'cabin_review', 'no_police_report'],
            'accident with injuries' => [RoadsideServiceTypeEnum::ACCIDENT_TOW, ['injuries_reported' => true], 'cabin_review', 'injuries_reported'],
            'accident without authorization' => [RoadsideServiceTypeEnum::ACCIDENT_TOW, ['authorization_to_move_vehicle' => false], 'not_proceed', 'no_authorization_to_move'],
            'locksmith beyond opening' => [RoadsideServiceTypeEnum::LOCKSMITH, ['only_opening_required' => false], 'not_proceed', 'out_of_locksmith_scope'],
            'driver not authorized' => [RoadsideServiceTypeEnum::DESIGNATED_DRIVER, ['authorizes_driver' => false], 'not_proceed', 'driver_not_authorized'],
        ];
    }

    #[DataProvider('knockouts')]
    public function testEachDiscardRule(
        RoadsideServiceTypeEnum $service,
        array $answers,
        string $outcome,
        string $reason
    ): void {
        $verdict = RoadsideIntakeRules::evaluate($service, $this->proceedingIntake($service, $answers));

        $this->assertSame($outcome, $verdict->outcome->value);
        $this->assertContains($reason, $verdict->reasons);
    }

    public function testAnUnusableSpareRecommendsATow(): void
    {
        $verdict = RoadsideIntakeRules::evaluate(
            RoadsideServiceTypeEnum::TIRE_CHANGE,
            $this->proceedingIntake(RoadsideServiceTypeEnum::TIRE_CHANGE, ['spare_tire_in_good_condition' => false]),
        );

        $this->assertSame(RoadsideServiceTypeEnum::LIGHT_TOW, $verdict->recommendedService);
    }

    public function testTheWorstOutcomeWinsAndEveryReasonIsKept(): void
    {
        $verdict = RoadsideIntakeRules::evaluate(
            RoadsideServiceTypeEnum::ACCIDENT_TOW,
            $this->proceedingIntake(RoadsideServiceTypeEnum::ACCIDENT_TOW, [
                'police_report_exists' => false,
                'authorization_to_move_vehicle' => false,
            ]),
        );

        $this->assertSame(RoadsideIntakeOutcomeEnum::NOT_PROCEED, $verdict->outcome);
        $this->assertSame(['no_police_report', 'no_authorization_to_move'], $verdict->reasons);
    }

    public function testAnIncompleteIntakeIsNotJudged(): void
    {
        $this->expectException(ValidationException::class);

        RoadsideIntakeRules::evaluate(RoadsideServiceTypeEnum::LOCKSMITH, ['contact_name' => 'Ana']);
    }
}
