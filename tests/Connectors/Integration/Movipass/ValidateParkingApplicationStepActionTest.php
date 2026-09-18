<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Kanvas\Connectors\Movipass\Actions\ValidateParkingApplicationStepAction;
use Kanvas\Connectors\Movipass\Enums\ParkingApplicationFieldEnum as Field;
use Kanvas\Connectors\Movipass\Enums\ParkingApplicationStepEnum as Step;
use Kanvas\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ValidateParkingApplicationStepActionTest extends TestCase
{
    public function testUnknownFieldIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unknown parking application field: not_a_real_key');

        new ValidateParkingApplicationStepAction(['not_a_real_key' => 'value'])->execute();
    }

    public function testBookkeepingFieldIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::STATUS->value => 'approved',
        ])->execute();
    }

    public function testFieldFromAnotherStepIsRejectedWhenStepGiven(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction(
            [Field::RATE_HOURLY->value => '10.00'],
            Step::IDENTIFICATION,
        )->execute();
    }

    public function testIdentificationStepValidPayloadIsNormalized(): void
    {
        $result = new ValidateParkingApplicationStepAction($this->validIdentificationPayload(), Step::IDENTIFICATION)->execute();

        $this->assertSame('natural', $result[Field::APPLICANT_TYPE->value]);
        $this->assertSame('juan@example.com', $result[Field::EMAIL->value]);
    }

    public function testCharacteristicsStepValidPayloadIsNormalized(): void
    {
        $result = new ValidateParkingApplicationStepAction($this->validCharacteristicsPayload(), Step::CHARACTERISTICS)->execute();

        $this->assertSame('open_lot', $result[Field::STRUCTURE->value]);
        $this->assertSame(4, $result[Field::CAMERA_COUNT->value]);
        $this->assertTrue($result[Field::HAS_LIGHTING->value]);
    }

    public function testLocationStepValidPayloadIsNormalized(): void
    {
        $result = new ValidateParkingApplicationStepAction($this->validLocationPayload(), Step::LOCATION)->execute();

        $this->assertSame(18.4861, $result[Field::LATITUDE->value]);
        $this->assertSame(-69.9312, $result[Field::LONGITUDE->value]);
    }

    public function testCapacityStepValidPayloadIsNormalized(): void
    {
        $result = new ValidateParkingApplicationStepAction($this->validCapacityPayload(), Step::CAPACITY)->execute();

        $this->assertSame(100, $result[Field::CAPACITY_TOTAL->value]);
        $this->assertTrue($result[Field::NUMBERED_SPACES->value]);
    }

    public function testPhotosStepValidPayloadIsNormalized(): void
    {
        $result = new ValidateParkingApplicationStepAction($this->validPhotosPayload(), Step::PHOTOS)->execute();

        $this->assertSame('a1b2c3d4-cover', $result[Field::COVER_PHOTO_UUID->value]);
    }

    public function testOperationsStepValidPayloadIsNormalized(): void
    {
        $result = new ValidateParkingApplicationStepAction($this->validOperationsPayload(), Step::OPERATIONS)->execute();

        $this->assertSame(['movipass', 'cash'], $result[Field::PAYMENT_METHODS->value]);
        $this->assertCount(2, $result[Field::CLOSURES->value]);
    }

    public function testRatesStepValidPayloadIsNormalized(): void
    {
        $result = new ValidateParkingApplicationStepAction($this->validRatesPayload(), Step::RATES)->execute();

        $this->assertSame(75.5, $result[Field::RATE_HOURLY->value]);
        $this->assertSame(4500.0, $result[Field::RATE_MONTHLY->value]);
    }

    public function testBankingStepValidPayloadIsNormalized(): void
    {
        $result = new ValidateParkingApplicationStepAction($this->validBankingPayload(), Step::BANKING)->execute();

        $this->assertSame('checking', $result[Field::BANK_ACCOUNT_TYPE->value]);
        $this->assertSame('settlements@example.com', $result[Field::SETTLEMENT_EMAIL->value]);
    }

    public function testContractStepValidPayloadIsNormalized(): void
    {
        $result = new ValidateParkingApplicationStepAction($this->validContractPayload(), Step::CONTRACT)->execute();

        $this->assertSame('v1', $result[Field::CONTRACT_VERSION->value]);
        $this->assertTrue($result[Field::CONTRACT_ACCEPTED->value]);
    }

    public function testContractAcceptedAtAndAcceptanceIpAreBookkeepingAndRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::CONTRACT_ACCEPTED_AT->value => '2026-09-16T10:00:00-04:00',
        ])->execute();
    }

    public function testCapacityValuesAreNormalizedToIntegers(): void
    {
        $result = new ValidateParkingApplicationStepAction([
            Field::CAPACITY_TOTAL->value => '100',
            Field::CAPACITY_LIGHT_VEHICLES->value => '80',
        ], Step::CAPACITY)->execute();

        $this->assertSame(100, $result[Field::CAPACITY_TOTAL->value]);
        $this->assertSame(80, $result[Field::CAPACITY_LIGHT_VEHICLES->value]);
    }

    public function testCapacityBreakdownOverTotalIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::CAPACITY_TOTAL->value => 10,
            Field::CAPACITY_LIGHT_VEHICLES->value => 8,
            Field::CAPACITY_MOTORCYCLES->value => 5,
            Field::CAPACITY_DISABILITY->value => 2,
        ], Step::CAPACITY)->execute();
    }

    public function testCapacityBreakdownChecksWhateverKeysArePresentAgainstTotal(): void
    {
        $result = new ValidateParkingApplicationStepAction([
            Field::CAPACITY_TOTAL->value => 10,
            Field::CAPACITY_LIGHT_VEHICLES->value => 8,
            Field::CAPACITY_MOTORCYCLES->value => 1,
        ], Step::CAPACITY)->execute();

        $this->assertSame(10, $result[Field::CAPACITY_TOTAL->value]);
    }

    public function testCapacityBreakdownOverTotalIsRejectedWithOnlyTwoOfFourKeysPresent(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::CAPACITY_TOTAL->value => 1,
            Field::CAPACITY_LIGHT_VEHICLES->value => 5,
            Field::CAPACITY_MOTORCYCLES->value => 5,
        ], Step::CAPACITY)->execute();
    }

    #[DataProvider('integerEdgeCaseProvider')]
    public function testIntegerEdgeCases(mixed $input, ?int $expected): void
    {
        if ($expected === null) {
            $this->expectException(ValidationException::class);
        }

        $result = new ValidateParkingApplicationStepAction([
            Field::CAPACITY_LIGHT_VEHICLES->value => $input,
        ])->execute();

        $this->assertSame($expected, $result[Field::CAPACITY_LIGHT_VEHICLES->value]);
    }

    public static function integerEdgeCaseProvider(): array
    {
        return [
            'zero as string' => ['0', 0],
            'padded numeric string' => [' 12 ', 12],
            'scientific notation' => ['1e3', 1000],
            'empty string' => ['', null],
            'trailing garbage' => ['12abc', null],
            'boolean true' => [true, null],
            'negative' => [-1, null],
        ];
    }

    public function testArrayValueForAStringFieldIsRejectedWithAValidationException(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::PARKING_NAME->value => ['not', 'a', 'string'],
        ])->execute();
    }

    public function testArrayValueForAScheduleBandOpenIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::SCHEDULE->value => [
                'weekdays' => [['open' => ['08:00'], 'close' => '10:00']],
            ],
        ], Step::OPERATIONS)->execute();
    }

    public function testArrayValueForAScheduleBandCloseIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::SCHEDULE->value => [
                'weekdays' => [['open' => '08:00', 'close' => ['10:00']]],
            ],
        ], Step::OPERATIONS)->execute();
    }

    public function testArrayValueForAClosureTypeIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::CLOSURES->value => [
                ['type' => ['recurring']],
            ],
        ], Step::OPERATIONS)->execute();
    }

    public function testArrayValueForAClosureDateIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::CLOSURES->value => [
                ['type' => 'one_off', 'date' => ['2026-12-25']],
            ],
        ], Step::OPERATIONS)->execute();
    }

    public function testArrayValueForAClosureReasonIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::CLOSURES->value => [
                ['type' => 'one_off', 'date' => '2026-12-25', 'reason' => ['Navidad']],
            ],
        ], Step::OPERATIONS)->execute();
    }

    public function testArrayValueForAPaymentMethodsEntryIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::PAYMENT_METHODS->value => ['movipass', ['cash']],
        ], Step::OPERATIONS)->execute();
    }

    public function testNullClearsAnOptionalStringField(): void
    {
        $result = new ValidateParkingApplicationStepAction([
            Field::PARKING_TYPE_OTHER->value => null,
        ])->execute();

        $this->assertNull($result[Field::PARKING_TYPE_OTHER->value]);
    }

    public function testNullClearsAnOptionalTextField(): void
    {
        $result = new ValidateParkingApplicationStepAction([
            Field::CAPACITY_NOTES->value => null,
        ])->execute();

        $this->assertNull($result[Field::CAPACITY_NOTES->value]);
    }

    public function testNullClearsAnOptionalEnumField(): void
    {
        $result = new ValidateParkingApplicationStepAction([
            Field::PARKING_TYPE->value => null,
        ])->execute();

        $this->assertNull($result[Field::PARKING_TYPE->value]);
    }

    public function testNullClearsAnOptionalEmailField(): void
    {
        $result = new ValidateParkingApplicationStepAction([
            Field::SETTLEMENT_EMAIL->value => null,
        ])->execute();

        $this->assertNull($result[Field::SETTLEMENT_EMAIL->value]);
    }

    public function testNullClearsAnOptionalIntegerField(): void
    {
        $result = new ValidateParkingApplicationStepAction([
            Field::CAMERA_COUNT->value => null,
        ])->execute();

        $this->assertNull($result[Field::CAMERA_COUNT->value]);
    }

    public function testNullClearsAnOptionalDecimalField(): void
    {
        $result = new ValidateParkingApplicationStepAction([
            Field::RATE_OVERNIGHT->value => null,
        ])->execute();

        $this->assertNull($result[Field::RATE_OVERNIGHT->value]);
    }

    public function testNullClearsAnOptionalBooleanField(): void
    {
        $result = new ValidateParkingApplicationStepAction([
            Field::HAS_LIGHTING->value => null,
        ])->execute();

        $this->assertNull($result[Field::HAS_LIGHTING->value]);
    }

    public function testInvalidBooleanIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::HAS_LIGHTING->value => 'maybe',
        ])->execute();
    }

    public function testBooleanAcceptsYesNoOnOff(): void
    {
        $result = new ValidateParkingApplicationStepAction([
            Field::HAS_LIGHTING->value => 'yes',
            Field::HAS_CAMERAS->value => 'off',
        ])->execute();

        $this->assertTrue($result[Field::HAS_LIGHTING->value]);
        $this->assertFalse($result[Field::HAS_CAMERAS->value]);
    }

    public function testCapacityTotalMustBeGreaterThanZero(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::CAPACITY_TOTAL->value => 0,
        ], Step::CAPACITY)->execute();
    }

    public function testRatesAcceptDecimalStrings(): void
    {
        $result = new ValidateParkingApplicationStepAction([
            Field::RATE_HOURLY->value => '75.50',
        ], Step::RATES)->execute();

        $this->assertSame(75.5, $result[Field::RATE_HOURLY->value]);
    }

    public function testNegativeRateIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::RATE_HOURLY->value => '-5',
        ], Step::RATES)->execute();
    }

    public function testNonNumericRateIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::RATE_HOURLY->value => 'free',
        ], Step::RATES)->execute();
    }

    public function testNegativeLatitudeIsAccepted(): void
    {
        $result = new ValidateParkingApplicationStepAction([
            Field::LATITUDE->value => '-33.45',
        ], Step::LOCATION)->execute();

        $this->assertSame(-33.45, $result[Field::LATITUDE->value]);
    }

    public function testLatitudeOutOfRangeIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::LATITUDE->value => '91',
        ], Step::LOCATION)->execute();
    }

    public function testLongitudeOutOfRangeIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::LONGITUDE->value => '-181',
        ], Step::LOCATION)->execute();
    }

    public function testBooleansAreNormalizedFromStrings(): void
    {
        $result = new ValidateParkingApplicationStepAction([
            Field::HAS_LIGHTING->value => 'true',
            Field::HAS_CAMERAS->value => '0',
            Field::CAMERA_COUNT->value => 2,
        ], Step::CHARACTERISTICS)->execute();

        $this->assertTrue($result[Field::HAS_LIGHTING->value]);
        $this->assertFalse($result[Field::HAS_CAMERAS->value]);
    }

    public function testScheduleBandCrossingMidnightIsAccepted(): void
    {
        $result = new ValidateParkingApplicationStepAction([
            Field::IS_24_7->value => false,
            Field::SCHEDULE->value => [
                'weekdays' => [['open' => '22:00', 'close' => '02:00']],
            ],
        ], Step::OPERATIONS)->execute();

        $this->assertSame(
            ['open' => '22:00', 'close' => '02:00'],
            $result[Field::SCHEDULE->value]['weekdays'][0]
        );
    }

    public function testOverlappingBandsAreRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::SCHEDULE->value => [
                'weekdays' => [
                    ['open' => '08:00', 'close' => '14:00'],
                    ['open' => '13:00', 'close' => '18:00'],
                ],
            ],
        ], Step::OPERATIONS)->execute();
    }

    public function testOverlappingBandsAcrossTheMidnightWrapAreRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::SCHEDULE->value => [
                'weekdays' => [
                    ['open' => '22:00', 'close' => '02:00'],
                    ['open' => '01:00', 'close' => '05:00'],
                ],
            ],
        ], Step::OPERATIONS)->execute();
    }

    public function testUnknownScheduleGroupIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::SCHEDULE->value => [
                'funday' => [['open' => '08:00', 'close' => '10:00']],
            ],
        ], Step::OPERATIONS)->execute();
    }

    public function testZeroLengthBandIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::SCHEDULE->value => [
                'weekdays' => [['open' => '09:00', 'close' => '09:00']],
            ],
        ], Step::OPERATIONS)->execute();
    }

    public function testFullDayBandIsAccepted(): void
    {
        $result = new ValidateParkingApplicationStepAction([
            Field::SCHEDULE->value => [
                'weekdays' => [['open' => '00:00', 'close' => '00:00']],
                'saturday' => [['open' => '08:00', 'close' => '14:00']],
            ],
        ], Step::OPERATIONS)->execute();

        $this->assertSame(
            ['open' => '00:00', 'close' => '00:00'],
            $result[Field::SCHEDULE->value]['weekdays'][0]
        );
    }

    public function testEmptyScheduleGroupsDoNotCountAsHavingASchedule(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::IS_24_7->value => false,
            Field::SCHEDULE->value => [
                'weekdays' => [],
            ],
        ], Step::OPERATIONS)->execute();
    }

    public function testRecurringAndOneOffClosuresAreAccepted(): void
    {
        $result = new ValidateParkingApplicationStepAction([
            Field::CLOSURES->value => [
                ['type' => 'recurring', 'weekday' => 0],
                ['type' => 'one_off', 'date' => '2026-12-25', 'reason' => 'Navidad'],
            ],
        ], Step::OPERATIONS)->execute();

        $this->assertSame(
            [
                ['type' => 'recurring', 'weekday' => 0],
                ['type' => 'one_off', 'date' => '2026-12-25', 'reason' => 'Navidad'],
            ],
            $result[Field::CLOSURES->value]
        );
    }

    public function testOneOffClosureWithNullDateIsRejectedAsInvalid(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('parking_application_closures one_off entries need a valid YYYY-MM-DD date');

        new ValidateParkingApplicationStepAction([
            Field::CLOSURES->value => [
                ['type' => 'one_off', 'date' => null],
            ],
        ], Step::OPERATIONS)->execute();
    }

    public function testOneOffClosureWithNullReasonIsDropped(): void
    {
        $result = new ValidateParkingApplicationStepAction([
            Field::CLOSURES->value => [
                ['type' => 'one_off', 'date' => '2026-12-25', 'reason' => null],
            ],
        ], Step::OPERATIONS)->execute();

        $this->assertSame(
            ['type' => 'one_off', 'date' => '2026-12-25'],
            $result[Field::CLOSURES->value][0]
        );
    }

    public function testClosureWithAnInvalidDateIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::CLOSURES->value => [
                ['type' => 'one_off', 'date' => '2026-13-40'],
            ],
        ], Step::OPERATIONS)->execute();
    }

    public function testClosureWithAnUnknownTypeIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::CLOSURES->value => [
                ['type' => 'weird'],
            ],
        ], Step::OPERATIONS)->execute();
    }

    public function testRecurringClosureWithWeekdaySevenIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::CLOSURES->value => [
                ['type' => 'recurring', 'weekday' => 7],
            ],
        ], Step::OPERATIONS)->execute();
    }

    public function testRecurringClosureWithAFractionalWeekdayIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::CLOSURES->value => [
                ['type' => 'recurring', 'weekday' => 3.5],
            ],
        ], Step::OPERATIONS)->execute();
    }

    public function testIs24_7TrueWithAScheduleIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::IS_24_7->value => true,
            Field::SCHEDULE->value => [
                'weekdays' => [['open' => '08:00', 'close' => '18:00']],
            ],
        ], Step::OPERATIONS)->execute();
    }

    public function testIs24_7FalseWithoutAScheduleIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::IS_24_7->value => false,
        ], Step::OPERATIONS)->execute();
    }

    public function testPaymentMethodsWithoutMovipassIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::PAYMENT_METHODS->value => ['cash'],
        ], Step::OPERATIONS)->execute();
    }

    public function testPaymentMethodsWithMovipassAndCashIsAccepted(): void
    {
        $result = new ValidateParkingApplicationStepAction([
            Field::PAYMENT_METHODS->value => ['movipass', 'cash'],
        ], Step::OPERATIONS)->execute();

        $this->assertSame(['movipass', 'cash'], $result[Field::PAYMENT_METHODS->value]);
    }

    public function testPaymentMethodsWithAnUnknownMethodIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::PAYMENT_METHODS->value => ['movipass', 'bitcoin'],
        ], Step::OPERATIONS)->execute();
    }

    public function testJuridicaApplicantWithoutRncIsRejectedOnAFullStepSubmission(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::APPLICANT_TYPE->value => 'juridica',
            Field::LEGAL_NAME->value => 'Parqueos SRL',
        ], Step::IDENTIFICATION)->execute();
    }

    public function testJuridicaApplicantWithRncAndLegalNameIsAccepted(): void
    {
        $result = new ValidateParkingApplicationStepAction([
            Field::APPLICANT_TYPE->value => 'juridica',
            Field::RNC->value => '131123456',
            Field::LEGAL_NAME->value => 'Parqueos SRL',
        ], Step::IDENTIFICATION)->execute();

        $this->assertSame('juridica', $result[Field::APPLICANT_TYPE->value]);
    }

    public function testNaturalApplicantWithoutNationalIdIsRejectedOnAFullStepSubmission(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::APPLICANT_TYPE->value => 'natural',
            Field::FULL_NAME->value => 'Juan Perez',
        ], Step::IDENTIFICATION)->execute();
    }

    public function testPartialPatchDoesNotEnforceApplicantIdentityRequiredNess(): void
    {
        $result = new ValidateParkingApplicationStepAction([
            Field::APPLICANT_TYPE->value => 'natural',
        ])->execute();

        $this->assertSame('natural', $result[Field::APPLICANT_TYPE->value]);
    }

    public function testPartialPatchDoesNotEnforceIs24_7RequiredNess(): void
    {
        $result = new ValidateParkingApplicationStepAction([
            Field::IS_24_7->value => false,
        ])->execute();

        $this->assertFalse($result[Field::IS_24_7->value]);
    }

    public function testFullStepSubmissionStillEnforcesIs24_7RequiredNess(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::IS_24_7->value => false,
        ], Step::OPERATIONS)->execute();
    }

    public function testContradictionRulesStillApplyOnAPartialPatch(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::IS_24_7->value => true,
            Field::SCHEDULE->value => [
                'weekdays' => [['open' => '08:00', 'close' => '18:00']],
            ],
        ])->execute();
    }

    public function testInvalidEmailFormatIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ValidateParkingApplicationStepAction([
            Field::EMAIL->value => 'not-an-email',
        ], Step::IDENTIFICATION)->execute();
    }

    public function testValidEmailIsNormalizedToLowercase(): void
    {
        $result = new ValidateParkingApplicationStepAction([
            Field::EMAIL->value => 'Juan@Example.COM',
        ], Step::IDENTIFICATION)->execute();

        $this->assertSame('juan@example.com', $result[Field::EMAIL->value]);
    }

    public function testEveryStepFieldAcceptsOrRejectsANullValueWithoutCrashing(): void
    {
        foreach (Field::cases() as $field) {
            if ($field->step() === null) {
                continue;
            }

            try {
                new ValidateParkingApplicationStepAction([$field->value => null])->execute();
            } catch (ValidationException) {
                continue;
            }
        }

        $this->expectNotToPerformAssertions();
    }

    private function validIdentificationPayload(): array
    {
        return [
            Field::APPLICANT_TYPE->value => 'natural',
            Field::NATIONAL_ID->value => '00112345678',
            Field::FULL_NAME->value => 'Juan Perez',
            Field::PHONE->value => '8095551234',
            Field::EMAIL->value => 'juan@example.com',
            Field::PARKING_NAME->value => 'Parqueo Juan',
        ];
    }

    private function validCharacteristicsPayload(): array
    {
        return [
            Field::PARKING_TYPE->value => 'commercial',
            Field::STRUCTURE->value => 'open_lot',
            Field::HAS_LIGHTING->value => true,
            Field::HAS_CAMERAS->value => true,
            Field::CAMERA_COUNT->value => 4,
            Field::HAS_GUARD->value => false,
            Field::HAS_ROOF->value => false,
            Field::HAS_ACCESS_CONTROL->value => true,
            Field::HAS_RESTROOMS->value => false,
            Field::IS_24_7_SECURITY->value => false,
        ];
    }

    private function validLocationPayload(): array
    {
        return [
            Field::ADDRESS->value => 'Av. Winston Churchill 123',
            Field::CITY->value => 'Santo Domingo',
            Field::PROVINCE->value => 'Distrito Nacional',
            Field::REFERENCES->value => 'Frente al banco',
            Field::LATITUDE->value => '18.4861',
            Field::LONGITUDE->value => '-69.9312',
            Field::COORDINATES_PENDING_VALIDATION->value => false,
        ];
    }

    private function validCapacityPayload(): array
    {
        return [
            Field::CAPACITY_TOTAL->value => 100,
            Field::CAPACITY_LIGHT_VEHICLES->value => 80,
            Field::CAPACITY_MOTORCYCLES->value => 15,
            Field::CAPACITY_DISABILITY->value => 5,
            Field::NUMBERED_SPACES->value => true,
            Field::CAPACITY_NOTES->value => 'Niveles 1 y 2',
        ];
    }

    private function validPhotosPayload(): array
    {
        return [
            Field::COVER_PHOTO_UUID->value => 'a1b2c3d4-cover',
        ];
    }

    private function validOperationsPayload(): array
    {
        return [
            Field::IS_24_7->value => false,
            Field::SCHEDULE->value => [
                'weekdays' => [['open' => '06:00', 'close' => '22:00']],
                'saturday' => [['open' => '07:00', 'close' => '20:00']],
                'sunday_holidays' => [['open' => '22:00', 'close' => '02:00']],
            ],
            Field::CLOSURES->value => [
                ['type' => 'recurring', 'weekday' => 0],
                ['type' => 'one_off', 'date' => '2026-12-25', 'reason' => 'Navidad'],
            ],
            Field::PAYMENT_METHODS->value => ['movipass', 'cash'],
        ];
    }

    private function validRatesPayload(): array
    {
        return [
            Field::RATE_HOURLY->value => '75.50',
            Field::RATE_MINIMUM_ENTRY->value => '50.00',
            Field::RATE_OVERNIGHT->value => '300.00',
            Field::RATE_MONTHLY->value => '4500.00',
        ];
    }

    private function validBankingPayload(): array
    {
        return [
            Field::BANK_NAME->value => 'Banco Popular',
            Field::BANK_ACCOUNT_TYPE->value => 'checking',
            Field::BANK_ACCOUNT_NUMBER->value => '1234567890',
            Field::BANK_ACCOUNT_HOLDER->value => 'Juan Perez',
            Field::SETTLEMENT_EMAIL->value => 'settlements@example.com',
        ];
    }

    private function validContractPayload(): array
    {
        return [
            Field::CONTRACT_VERSION->value => 'v1',
            Field::CONTRACT_ACCEPTED->value => true,
        ];
    }
}
