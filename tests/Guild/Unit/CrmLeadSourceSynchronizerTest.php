<?php

declare(strict_types=1);

namespace Tests\Guild\Unit;

use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum as DealerSocketCustomFieldEnum;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum as DriveCentricConfigurationEnum;
use Kanvas\Connectors\DriveCentric\Enums\CustomFieldEnums as DriveCentricCustomFieldEnum;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum as EleadCustomFieldEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as VinSolutionCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\CrmLeadSourceSynchronizer;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * `canSync()` decides whether the follow-up engine spends a CRM call on a lead. Pure decision over
 * company + lead custom fields, so it is pinned without touching a vendor. `sync()` itself is the
 * existing SalesAssist pull and is covered by each connector's own tests.
 */
final class CrmLeadSourceSynchronizerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testCompanyWithoutACrmIsNotSyncable(): void
    {
        $lead = $this->lead(companyConfig: [], leadFields: [EleadCustomFieldEnum::LEAD_ID->value => '123']);

        $this->assertFalse(new CrmLeadSourceSynchronizer()->canSync($lead));
    }

    public function testCrmCompanyWithoutTheLeadExternalIdIsNotSyncable(): void
    {
        $lead = $this->lead(
            companyConfig: [EleadCustomFieldEnum::COMPANY->value => 'dealer-1'],
            leadFields: [EleadCustomFieldEnum::LEAD_ID->value => null],
        );

        $this->assertFalse(new CrmLeadSourceSynchronizer()->canSync($lead));
    }

    public function testEachCrmIsSyncableWhenItsOwnExternalIdIsPresent(): void
    {
        $cases = [
            [EleadCustomFieldEnum::COMPANY->value, EleadCustomFieldEnum::LEAD_ID->value],
            [VinSolutionCustomFieldEnum::COMPANY->value, VinSolutionCustomFieldEnum::LEADS->value],
            [DealerSocketCustomFieldEnum::DEALER_SOCKET_CREDENTIAL->value, DealerSocketCustomFieldEnum::DEALER_SOCKET_CUSTOMER_ID->value],
            [DriveCentricConfigurationEnum::STORE_ID->value, DriveCentricCustomFieldEnum::DRIVE_CENTRIC_DEAL_ID->value],
        ];

        foreach ($cases as [$companyKey, $externalIdKey]) {
            $lead = $this->lead(companyConfig: [$companyKey => 'configured'], leadFields: [$externalIdKey => '42']);

            $this->assertTrue(new CrmLeadSourceSynchronizer()->canSync($lead), $companyKey);
        }
    }

    /**
     * @param array<string, mixed> $companyConfig
     * @param array<string, mixed> $leadFields
     */
    private function lead(array $companyConfig, array $leadFields): Lead
    {
        $company = Mockery::mock(Companies::class);
        $company->shouldReceive('get')->andReturnUsing(fn (string $key) => $companyConfig[$key] ?? null);

        $lead = Mockery::mock(Lead::class);
        $lead->shouldReceive('getAttribute')->with('company')->andReturn($company);
        $lead->shouldReceive('get')->andReturnUsing(fn (string $key) => $leadFields[$key] ?? null);

        return $lead;
    }
}
