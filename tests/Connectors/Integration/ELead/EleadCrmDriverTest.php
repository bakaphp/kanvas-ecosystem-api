<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\ELead;

use Baka\Contracts\CompanyInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\Elead\Services\EleadCrmDriver;
use Kanvas\Connectors\SalesAssist\DataTransferObject\Crm\CrmCustomer;
use Kanvas\Connectors\SalesAssist\DataTransferObject\Crm\CrmLeadInfo;
use Kanvas\Connectors\SalesAssist\Enums\CrmTypeEnum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

final class EleadCrmDriverTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testTheTypeBuildsTheDriverAndItsFields(): void
    {
        $driver = CrmTypeEnum::ELEAD->driver();

        $this->assertInstanceOf(EleadCrmDriver::class, $driver);
        $this->assertSame(CrmTypeEnum::ELEAD, $driver->type());
        $this->assertSame(CustomFieldEnum::PERSON_ID->value, $driver->clientIdField());
        $this->assertSame(CustomFieldEnum::LEAD_ID->value, $driver->leadIdField());
        $this->assertFalse($driver->canCreate());
        $this->assertNull($driver->resolveOwnerId('Perez, Ana', new Companies()));
        $this->assertSame([], $driver->customerCustomFields(new CrmCustomer(firstname: 'Ana')));
        $this->assertSame([], $driver->leadCustomFields(new CrmLeadInfo(status: 'Open')));
        $this->assertSame('Open', $driver->resolveStatusName('Open'));
    }

    public function testAnEleadSubscriptionMakesTheCompanyConfigured(): void
    {
        $this->assertTrue(new EleadCrmDriver()->isConfigured($this->companyWith('SUB-1')));
        $this->assertFalse(new EleadCrmDriver()->isConfigured($this->companyWith(null)));
        $this->assertFalse(new EleadCrmDriver()->isConfigured($this->companyWith('')));
    }

    private function companyWith(?string $subscription): CompanyInterface
    {
        $company = Mockery::mock(CompanyInterface::class);
        $company->shouldReceive('get')->with(CustomFieldEnum::COMPANY->value)->andReturn($subscription);

        return $company;
    }
}
