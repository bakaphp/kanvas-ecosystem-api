<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass\ParkingApplication;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\ParkingApplications\Enums\ParkingApplicationFieldEnum;
use Kanvas\Connectors\Movipass\ParkingApplications\Enums\ParkingApplicationStepEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class ParkingApplicationFieldEnumTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'ecosystem'];

    private const array BOOKKEEPING_FIELDS = [
        ParkingApplicationFieldEnum::STATUS,
        ParkingApplicationFieldEnum::STATUS_REASON,
        ParkingApplicationFieldEnum::STEP,
        ParkingApplicationFieldEnum::REVIEWED_BY,
        ParkingApplicationFieldEnum::REVIEWED_AT,
        ParkingApplicationFieldEnum::NUMBER,
        ParkingApplicationFieldEnum::CONTRACT_ACCEPTED_AT,
        ParkingApplicationFieldEnum::CONTRACT_ACCEPTANCE_IP,
    ];

    private const array SENSITIVE_FIELDS = [
        ParkingApplicationFieldEnum::BANK_NAME,
        ParkingApplicationFieldEnum::BANK_ACCOUNT_TYPE,
        ParkingApplicationFieldEnum::BANK_ACCOUNT_NUMBER,
        ParkingApplicationFieldEnum::BANK_ACCOUNT_HOLDER,
        ParkingApplicationFieldEnum::SETTLEMENT_EMAIL,
    ];

    public function testEveryNonBookkeepingFieldMapsToExactlyOneStep(): void
    {
        foreach (ParkingApplicationFieldEnum::cases() as $field) {
            if (in_array($field, self::BOOKKEEPING_FIELDS, true)) {
                $this->assertNull($field->step(), "{$field->value} is bookkeeping and must not map to a step");

                continue;
            }

            $this->assertInstanceOf(
                ParkingApplicationStepEnum::class,
                $field->step(),
                "{$field->value} must map to exactly one step"
            );
        }
    }

    public function testEachStepFieldsCoversAllItsCasesAndNothingElse(): void
    {
        $expectedByStep = [
            ParkingApplicationStepEnum::IDENTIFICATION->value => [
                ParkingApplicationFieldEnum::APPLICANT_TYPE,
                ParkingApplicationFieldEnum::NATIONAL_ID,
                ParkingApplicationFieldEnum::FULL_NAME,
                ParkingApplicationFieldEnum::PHONE,
                ParkingApplicationFieldEnum::EMAIL,
                ParkingApplicationFieldEnum::RNC,
                ParkingApplicationFieldEnum::LEGAL_NAME,
                ParkingApplicationFieldEnum::COMMERCIAL_NAME,
                ParkingApplicationFieldEnum::LEGAL_REPRESENTATIVE,
                ParkingApplicationFieldEnum::PARKING_NAME,
            ],
            ParkingApplicationStepEnum::CHARACTERISTICS->value => [
                ParkingApplicationFieldEnum::PARKING_TYPE,
                ParkingApplicationFieldEnum::PARKING_TYPE_OTHER,
                ParkingApplicationFieldEnum::STRUCTURE,
                ParkingApplicationFieldEnum::HAS_LIGHTING,
                ParkingApplicationFieldEnum::HAS_CAMERAS,
                ParkingApplicationFieldEnum::CAMERA_COUNT,
                ParkingApplicationFieldEnum::HAS_GUARD,
                ParkingApplicationFieldEnum::HAS_ROOF,
                ParkingApplicationFieldEnum::HAS_ACCESS_CONTROL,
                ParkingApplicationFieldEnum::HAS_RESTROOMS,
                ParkingApplicationFieldEnum::IS_24_7_SECURITY,
            ],
            ParkingApplicationStepEnum::LOCATION->value => [
                ParkingApplicationFieldEnum::ADDRESS,
                ParkingApplicationFieldEnum::CITY,
                ParkingApplicationFieldEnum::PROVINCE,
                ParkingApplicationFieldEnum::REFERENCES,
                ParkingApplicationFieldEnum::LATITUDE,
                ParkingApplicationFieldEnum::LONGITUDE,
                ParkingApplicationFieldEnum::COORDINATES_PENDING_VALIDATION,
            ],
            ParkingApplicationStepEnum::CAPACITY->value => [
                ParkingApplicationFieldEnum::CAPACITY_TOTAL,
                ParkingApplicationFieldEnum::CAPACITY_LIGHT_VEHICLES,
                ParkingApplicationFieldEnum::CAPACITY_MOTORCYCLES,
                ParkingApplicationFieldEnum::CAPACITY_DISABILITY,
                ParkingApplicationFieldEnum::NUMBERED_SPACES,
                ParkingApplicationFieldEnum::CAPACITY_NOTES,
            ],
            ParkingApplicationStepEnum::PHOTOS->value => [
                ParkingApplicationFieldEnum::COVER_PHOTO_UUID,
            ],
            ParkingApplicationStepEnum::OPERATIONS->value => [
                ParkingApplicationFieldEnum::IS_24_7,
                ParkingApplicationFieldEnum::SCHEDULE,
                ParkingApplicationFieldEnum::CLOSURES,
                ParkingApplicationFieldEnum::PAYMENT_METHODS,
            ],
            ParkingApplicationStepEnum::RATES->value => [
                ParkingApplicationFieldEnum::RATE_HOURLY,
                ParkingApplicationFieldEnum::RATE_MINIMUM_ENTRY,
                ParkingApplicationFieldEnum::RATE_OVERNIGHT,
                ParkingApplicationFieldEnum::RATE_MONTHLY,
            ],
            ParkingApplicationStepEnum::BANKING->value => [
                ParkingApplicationFieldEnum::BANK_NAME,
                ParkingApplicationFieldEnum::BANK_ACCOUNT_TYPE,
                ParkingApplicationFieldEnum::BANK_ACCOUNT_NUMBER,
                ParkingApplicationFieldEnum::BANK_ACCOUNT_HOLDER,
                ParkingApplicationFieldEnum::SETTLEMENT_EMAIL,
            ],
            ParkingApplicationStepEnum::CONTRACT->value => [
                ParkingApplicationFieldEnum::CONTRACT_VERSION,
                ParkingApplicationFieldEnum::CONTRACT_ACCEPTED,
            ],
        ];

        foreach (ParkingApplicationStepEnum::cases() as $step) {
            $this->assertEqualsCanonicalizing(
                $expectedByStep[$step->value],
                $step->fields(),
                "step {$step->value} field list mismatch"
            );
        }

        $allExpectedFields = array_merge(...array_values($expectedByStep));
        $this->assertCount(count(array_unique($allExpectedFields, SORT_REGULAR)), $allExpectedFields);
    }

    public function testOnlyBankingKeysAreSensitive(): void
    {
        foreach (ParkingApplicationFieldEnum::cases() as $field) {
            $this->assertSame(
                in_array($field, self::SENSITIVE_FIELDS, true),
                $field->isSensitive(),
                "{$field->value} sensitivity mismatch"
            );
        }
    }

    public function testWriteToAndReadFromRoundTripAPlainKey(): void
    {
        $lead = $this->makeLead();

        ParkingApplicationFieldEnum::PARKING_NAME->writeTo($lead, 'Plaza Central Parking');

        $this->assertEquals('Plaza Central Parking', ParkingApplicationFieldEnum::PARKING_NAME->readFrom($lead));
        $this->assertEquals('Plaza Central Parking', $lead->get(ParkingApplicationFieldEnum::PARKING_NAME->value));
    }

    public function testWriteToAndReadFromRoundTripASensitiveKeyEncryptedAtRest(): void
    {
        $lead = $this->makeLead();

        ParkingApplicationFieldEnum::BANK_ACCOUNT_NUMBER->writeTo($lead, '1234567890');

        $this->assertEquals(
            '1234567890',
            ParkingApplicationFieldEnum::BANK_ACCOUNT_NUMBER->readFrom($lead)
        );

        $raw = $lead->get(ParkingApplicationFieldEnum::BANK_ACCOUNT_NUMBER->value);
        $this->assertNotEquals('1234567890', $raw);
    }

    private function makeLead(): Lead
    {
        $app = app(Apps::class);
        $company = Auth::user()->getCurrentCompany();

        return Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();
    }
}
