<?php

declare(strict_types=1);

namespace Tests\Guild\Leads;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

/**
 * The UI and every CRM pull close a lead through `leads_status_id`; the integer `status` column is
 * never written and sits at its default for every row. `isOpen()` and the `isOpen` scope must read
 * the named status, or a closed lead keeps passing every "open leads only" gate.
 */
class LeadOpenStateTest extends TestCase
{
    use DatabaseTransactions;

    public function testClosingThroughTheNamedStatusMakesIsOpenFalse(): void
    {
        $lead = $this->makeLead();

        $this->assertTrue($lead->isOpen());

        $lead->close();
        $lead->refresh();

        $this->assertSame(0, (int) $lead->getAttributeValue('status'));
        $this->assertFalse($lead->isOpen());
        $this->assertFalse($lead->hasOpenLeadStatus());
    }

    public function testIsOpenScopeExcludesALeadClosedThroughTheNamedStatus(): void
    {
        $open = $this->makeLead();
        $closed = $this->makeLead();
        $closed->close();

        $ids = Lead::query()
            ->whereIn('id', [$open->getId(), $closed->getId()])
            ->isOpen($open->company)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertSame([$open->getId()], $ids);
    }

    private function makeLead(): Lead
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();
    }
}
