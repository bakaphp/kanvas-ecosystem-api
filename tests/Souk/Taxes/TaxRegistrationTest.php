<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Taxes\Actions\CreateTaxRegistrationAction;
use Kanvas\Souk\Taxes\Actions\UpdateTaxRegistrationAction;
use Kanvas\Souk\Taxes\Models\TaxRegistration;
use Tests\TestCase;

final class TaxRegistrationTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce'];

    private Apps $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = app(Apps::class);
    }

    public function testCreateRegistrationSucceeds(): void
    {
        $registration = new CreateTaxRegistrationAction(
            $this->app,
            [
                'jurisdiction_country' => 'US',
                'jurisdiction_state' => 'NY',
                'registration_number' => 'NY-REG-001',
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-12-31',
            ],
        )->execute();

        $this->assertInstanceOf(TaxRegistration::class, $registration);
        $this->assertSame('US', $registration->jurisdiction_country);
        $this->assertSame('NY', $registration->jurisdiction_state);
        $this->assertSame($this->app->getId(), (int) $registration->apps_id);
    }

    public function testOverlappingDateRangeThrowsValidationException(): void
    {
        new CreateTaxRegistrationAction(
            $this->app,
            [
                'jurisdiction_country' => 'US',
                'jurisdiction_state' => 'CA',
                'registration_number' => 'CA-REG-001',
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-12-31',
            ],
        )->execute();

        $this->expectException(ValidationException::class);

        new CreateTaxRegistrationAction(
            $this->app,
            [
                'jurisdiction_country' => 'US',
                'jurisdiction_state' => 'CA',
                'registration_number' => 'CA-REG-002',
                'effective_from' => '2026-06-01',
                'effective_to' => '2027-05-31',
            ],
        )->execute();
    }

    public function testNonOverlappingDateRangesInSameJurisdictionBothSucceed(): void
    {
        new CreateTaxRegistrationAction(
            $this->app,
            [
                'jurisdiction_country' => 'US',
                'jurisdiction_state' => 'FL',
                'effective_from' => '2025-01-01',
                'effective_to' => '2025-12-31',
            ],
        )->execute();

        $second = new CreateTaxRegistrationAction(
            $this->app,
            [
                'jurisdiction_country' => 'US',
                'jurisdiction_state' => 'FL',
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-12-31',
            ],
        )->execute();

        $this->assertInstanceOf(TaxRegistration::class, $second);
    }

    public function testDifferentJurisdictionsDoNotConflict(): void
    {
        new CreateTaxRegistrationAction(
            $this->app,
            [
                'jurisdiction_country' => 'US',
                'jurisdiction_state' => 'TX',
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-12-31',
            ],
        )->execute();

        $second = new CreateTaxRegistrationAction(
            $this->app,
            [
                'jurisdiction_country' => 'US',
                'jurisdiction_state' => 'WA',
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-12-31',
            ],
        )->execute();

        $this->assertInstanceOf(TaxRegistration::class, $second);
    }

    public function testOpenEndedRegistrationBlocksNewRegistrationInSameJurisdiction(): void
    {
        new CreateTaxRegistrationAction(
            $this->app,
            [
                'jurisdiction_country' => 'MX',
                'jurisdiction_state' => null,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ],
        )->execute();

        $this->expectException(ValidationException::class);

        new CreateTaxRegistrationAction(
            $this->app,
            [
                'jurisdiction_country' => 'MX',
                'jurisdiction_state' => null,
                'effective_from' => '2027-01-01',
                'effective_to' => null,
            ],
        )->execute();
    }

    public function testUpdateDoesNotConflictWithItself(): void
    {
        $registration = new CreateTaxRegistrationAction(
            $this->app,
            [
                'jurisdiction_country' => 'DO',
                'jurisdiction_state' => null,
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-12-31',
            ],
        )->execute();

        $updated = new UpdateTaxRegistrationAction(
            $registration,
            ['registration_number' => 'DO-REG-999'],
        )->execute();

        $this->assertSame('DO-REG-999', $updated->registration_number);
    }

    public function testUpdateConflictsWithSiblingRegistration(): void
    {
        new CreateTaxRegistrationAction(
            $this->app,
            [
                'jurisdiction_country' => 'US',
                'jurisdiction_state' => 'OH',
                'effective_from' => '2026-06-01',
                'effective_to' => '2026-12-31',
            ],
        )->execute();

        $second = new CreateTaxRegistrationAction(
            $this->app,
            [
                'jurisdiction_country' => 'US',
                'jurisdiction_state' => 'OH',
                'effective_from' => '2025-01-01',
                'effective_to' => '2025-12-31',
            ],
        )->execute();

        $this->expectException(ValidationException::class);

        new UpdateTaxRegistrationAction(
            $second,
            ['effective_to' => '2026-09-30'],
        )->execute();
    }
}
