<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\ELead;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Actions\SyncLeadSourceAction;
use Kanvas\Connectors\Elead\Support\EleadCache;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\LeadSources\Models\LeadSource;
use Tests\TestCase;

final class SyncLeadSourceActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    public function testSourcesWithoutDescriptionShareOneLeadTypePerUpType(): void
    {
        $app = app(Apps::class);
        $company = Auth::user()->getCurrentCompany();
        $upType = 'Internet ' . uniqid();
        $cache = new EleadCache($app, $company);

        $cache->setReference('lead_sources', 'all', [
            ['name' => 'Autotrader ' . uniqid(), 'upType' => $upType, 'isActive' => true],
            ['name' => 'Cars.com ' . uniqid(), 'upType' => $upType, 'isActive' => false],
            ['name' => 'Walk In ' . uniqid(), 'upType' => null, 'isActive' => true],
            ['name' => '  ', 'upType' => $upType, 'isActive' => true],
        ]);

        try {
            $this->assertSame(3, new SyncLeadSourceAction($app, $company)->execute());
            $this->assertSame(3, new SyncLeadSourceAction($app, $company)->execute());
        } finally {
            $cache->invalidate('lead_sources', 'all');
        }

        $leadTypes = LeadType::fromApp($app)->fromCompany($company)->where('name', $upType)->get();
        $this->assertCount(1, $leadTypes);

        $sources = LeadSource::fromApp($app)
            ->fromCompany($company)
            ->where('leads_types_id', $leadTypes->first()->getId())
            ->get();

        $this->assertCount(2, $sources);
        $carsCom = $sources->first(fn (LeadSource $source): bool => str_starts_with($source->name, 'Cars.com'));
        $this->assertFalse((bool) $carsCom->is_active);
    }
}
