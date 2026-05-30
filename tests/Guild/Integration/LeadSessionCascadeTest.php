<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Illuminate\Support\Str;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Sessions\Models\Session;
use Tests\TestCase;

final class LeadSessionCascadeTest extends TestCase
{
    public function testSoftDeleteLeadRemovesIntelligenceSessions(): void
    {
        $company = auth()->user()->getCurrentCompany();

        $people = new People();
        $people->users_id = auth()->user()->getId();
        $people->companies_id = $company->getId();
        $people->name = 'Test People';
        $people->saveOrFail();

        $lead = new Lead();
        $lead->companies_id = $company->getId();
        $lead->companies_branches_id = $company->branch()->firstOrFail()->getId();
        $lead->users_id = auth()->user()->getId();
        $lead->people_id = $people->getId();
        $lead->title = 'Test Lead';
        $lead->leads_receivers_id = 0;
        $lead->leads_owner_id = $lead->users_id;
        $lead->saveOrFail();

        $session = new Session();
        $session->companies_id = $company->getId();
        $session->uuid = Str::uuid()->toString();
        $session->entity_namespace = Lead::class;
        $session->entity_id = $lead->getId();
        $session->user = [];
        $session->content = [];
        $session->saveOrFail();

        $activeQuery = fn () => Session::where('entity_namespace', Lead::class)
            ->where('entity_id', $lead->getId());

        $this->assertSame(1, $activeQuery()->count());

        $lead->softDelete();

        $this->assertSame(0, $activeQuery()->count());

        $trashed = Session::withTrashed()
            ->where('entity_namespace', Lead::class)
            ->where('entity_id', $lead->getId())
            ->first();

        $this->assertNotNull($trashed);
        $this->assertSame(1, (int) $trashed->is_deleted);
    }
}
