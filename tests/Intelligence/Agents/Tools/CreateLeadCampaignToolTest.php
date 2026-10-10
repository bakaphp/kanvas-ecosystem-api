<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Campaigns\Jobs\ProcessLeadCampaignJob;
use Kanvas\Guild\Campaigns\Models\Campaign;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateLeadCampaignTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class CreateLeadCampaignToolTest extends TestCase
{
    private function freshPerson(Companies $company): People
    {
        $person = People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->withUserId(auth()->user()->getId())
            ->create();
        $person->contacts()->delete();

        return $person;
    }

    private function tool(Companies $company, ?Users $user = null): CreateLeadCampaignTool
    {
        return new CreateLeadCampaignTool()->withContext(app(Apps::class), $company, $user ?? auth()->user());
    }

    public function testCreatesCampaignForEligiblePeopleOnly(): void
    {
        Queue::fake();
        $company = Companies::factory()->create();

        $eligible = $this->freshPerson($company);
        $eligible->addEmail('eligible@example.com');

        $doNotContact = $this->freshPerson($company);
        $doNotContact->addEmail('blocked@example.com');
        $doNotContact->set('do_not_contact', 1);

        $result = $this->tool($company)->__invoke(
            people_ids: [$eligible->getId(), $doNotContact->getId()],
            message: 'Hello from the team',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['total_recipients']);
        $this->assertCount(1, $result['excluded']);

        $campaign = Campaign::findOrFail($result['campaign_id']);
        $this->assertSame(1, $campaign->recipients()->count());
        $this->assertNull($campaign->recipients()->first()->leads_id);
        $this->assertSame($eligible->getId(), $campaign->recipients()->first()->peoples_id);

        Queue::assertPushed(ProcessLeadCampaignJob::class);
    }

    public function testPeopleIdsFromAnotherCompanyAreExcludedNotLeaked(): void
    {
        Queue::fake();
        $company = Companies::factory()->create();
        $otherCompany = Companies::factory()->create();

        $foreignPerson = $this->freshPerson($otherCompany);
        $foreignPerson->addEmail('foreign@example.com');

        $result = $this->tool($company)->__invoke(
            people_ids: [$foreignPerson->getId()],
            message: 'Hello',
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame(0, Campaign::query()->where('companies_id', $company->getId())->count());
        Queue::assertNotPushed(ProcessLeadCampaignJob::class);
    }

    public function testNonAdminIsRejected(): void
    {
        // A bare factory user has no roles → isAdmin() is false.
        $nonAdmin = Users::factory()->create();
        $company = Companies::factory()->create();

        $result = $this->tool($company, $nonAdmin)->__invoke(
            people_ids: [1],
            message: 'Hello',
        );

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('administrator', $result['message']);
    }
}
