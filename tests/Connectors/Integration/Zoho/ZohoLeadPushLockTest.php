<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Tests\Connectors\Integration\Zoho\Concerns\BuildsZohoDeal;
use Tests\Connectors\Integration\Zoho\Concerns\StubsZohoLeadModule;
use Tests\TestCase;
use Webleit\ZohoCrmApi\Models\Record;

/**
 * Two rules pushing the same new lead at once each created it in Zoho: the row lock that makes the
 * second push wait was taken outside any transaction on the lead's connection.
 */
final class ZohoLeadPushLockTest extends TestCase
{
    use BuildsZohoDeal;
    use DatabaseTransactions;
    use StubsZohoLeadModule;

    protected $connectionsToTransact = [null, 'crm', 'social', 'ecosystem'];

    public function testTheLeadRowLockIsHeldWhileZohoCreatesTheLead(): void
    {
        $lead = $this->makeLead($this->makeZohoCompany());
        $levelOutside = DB::connection('crm')->transactionLevel();
        $levelDuringCreate = null;

        $module = $this->module();
        $module->expects($this->once())
            ->method('create')
            ->willReturnCallback(function () use (&$levelDuringCreate, $module) {
                $levelDuringCreate = DB::connection('crm')->transactionLevel();

                return new Record(['id' => 'zl-locked'], $module);
            });

        $this->action($lead, $module)->execute();

        $this->assertSame($levelOutside + 1, $levelDuringCreate);
    }

    public function testASecondPushUpdatesTheLeadTheFirstOneCreated(): void
    {
        $lead = $this->makeLead($this->makeZohoCompany());

        $module = $this->module();
        $module->expects($this->once())
            ->method('create')
            ->willReturn(new Record(['id' => 'zl-once'], $module));
        $module->method('get')->with('zl-once')->willReturn(new Record(['id' => 'zl-once'], $module));
        $module->expects($this->once())
            ->method('update')
            ->with('zl-once')
            ->willReturn(new Record(['id' => 'zl-once'], $module));

        $this->action($lead, $module)->execute();
        $this->action($lead, $module)->execute();

        $this->assertSame('zl-once', $lead->get(CustomFieldEnum::ZOHO_LEAD_ID->value));
    }

    public function testThePayloadUsesTheLeadReloadedUnderTheLock(): void
    {
        $lead = $this->makeLead($this->makeZohoCompany());
        $description = 'Description updated while the push was waiting.';
        $lead->newQuery()->whereKey($lead->getId())->update(['description' => $description]);

        $module = $this->module();
        $module->expects($this->once())
            ->method('create')
            ->with($this->callback(fn (array $data): bool => ($data['Description'] ?? null) === $description))
            ->willReturn(new Record(['id' => 'zl-current'], $module));

        $this->action($lead, $module)->execute();
    }
}
