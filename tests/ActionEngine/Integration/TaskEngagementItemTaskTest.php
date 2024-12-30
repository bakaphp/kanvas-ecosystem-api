<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Integration;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Kanvas\ActionEngine\CheckList\Repositories\TaskEngagementItemRepository;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class TaskEngagementItemTaskTest extends TestCase
{
    public function testGetLeadTaskItems(): void
    {
        $company = auth()->user()->getCurrentCompany();

        /**
         * @todo move to factory
         */
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

        $leadTaskItems = TaskEngagementItemRepository::getLeadsTaskItems($lead);

        $this->assertInstanceOf(Builder::class, $leadTaskItems);
        $this->assertIsArray($leadTaskItems->get()->toArray());
    }

    public function testChangeCheckListStatus(): void
    {
        $lead = Lead::factory()->create();
        //$eng
        /**
         * @todo
         * - create action factory
         * - create company action factory
         * - create engagement message for one status
         * - move engagement checklist status
         * - create another engagement message for another status
         * - move engagement checklist status
         */
        
    }
}
