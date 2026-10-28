<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Taxes\Models\CustomerTaxExemption;
use Tests\TestCase;

final class CustomerTaxExemptionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce'];

    private Apps $kanvasApp;
    private int $peopleId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->peopleId = random_int(900000000, 999999999);
    }

    private function exemption(array $overrides = []): CustomerTaxExemption
    {
        $exemption = new CustomerTaxExemption();
        $exemption->apps_id = $this->kanvasApp->getId();
        $exemption->people_id = $this->peopleId;
        $exemption->exemption_type = 'resale';
        $exemption->jurisdiction_country = 'DO';
        $exemption->is_active = true;

        foreach ($overrides as $field => $value) {
            $exemption->{$field} = $value;
        }

        $exemption->saveOrFail();

        return $exemption;
    }

    public function testActiveExemptionWithoutDatesIsFound(): void
    {
        $exemption = $this->exemption();

        $this->assertSame($exemption->getId(), CustomerTaxExemption::activeForPeople($this->kanvasApp, $this->peopleId)?->getId());
    }

    public function testNullPeopleHasNoExemption(): void
    {
        $this->exemption();

        $this->assertNull(CustomerTaxExemption::activeForPeople($this->kanvasApp, null));
    }

    public function testInactiveExemptionIsIgnored(): void
    {
        $this->exemption(['is_active' => false]);

        $this->assertNull(CustomerTaxExemption::activeForPeople($this->kanvasApp, $this->peopleId));
    }

    public function testExpiredExemptionIsIgnored(): void
    {
        $this->exemption(['valid_to' => Carbon::yesterday()->toDateString()]);
        $this->exemption(['expires_at' => Carbon::yesterday()->toDateString()]);

        $this->assertNull(CustomerTaxExemption::activeForPeople($this->kanvasApp, $this->peopleId));
    }

    public function testNotYetValidExemptionIsIgnored(): void
    {
        $this->exemption(['valid_from' => Carbon::tomorrow()->toDateString()]);

        $this->assertNull(CustomerTaxExemption::activeForPeople($this->kanvasApp, $this->peopleId));
    }

    public function testExemptionValidThroughTodayIsFound(): void
    {
        $exemption = $this->exemption([
            'valid_from' => Carbon::today()->toDateString(),
            'valid_to' => Carbon::today()->toDateString(),
            'expires_at' => Carbon::today()->toDateString(),
        ]);

        $this->assertSame($exemption->getId(), CustomerTaxExemption::activeForPeople($this->kanvasApp, $this->peopleId)?->getId());
    }
}
