<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadFileCountService;
use Tests\TestCase;

final class LeadFileCountServiceTest extends TestCase
{
    public function testTheFirstNumberOfABaseIsOne(): void
    {
        $lead = $this->makeLead();

        $this->assertSame(1, new LeadFileCountService($lead)->reserve('drivers-license-front'));
        $this->assertSame([['name' => 'drivers-license-front', 'count' => 1]], $lead->get('file-count'));
    }

    public function testTheCounterAdvancesAndLeavesOtherBasesUntouched(): void
    {
        $lead = $this->makeLead();
        $lead->set('file-count', [
            ['name' => 'upload', 'count' => 4],
            ['name' => 'drivers-license-front', 'count' => 2],
        ]);

        $this->assertSame(3, new LeadFileCountService($lead)->reserve('drivers-license-front'));
        $this->assertSame(1, new LeadFileCountService($lead)->reserve('drivers-license-back'));
        $this->assertSame([
            ['name' => 'upload', 'count' => 4],
            ['name' => 'drivers-license-front', 'count' => 3],
            ['name' => 'drivers-license-back', 'count' => 1],
        ], $lead->get('file-count'));
    }

    public function testANonArrayValueStartsAtOne(): void
    {
        $lead = $this->makeLead();
        $lead->set('file-count', 'not-a-list');

        $this->assertSame(1, new LeadFileCountService($lead)->reserve('drivers-license-combined'));
        $this->assertSame([['name' => 'drivers-license-combined', 'count' => 1]], $lead->get('file-count'));
    }

    private function makeLead(): Lead
    {
        return Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();
    }
}
