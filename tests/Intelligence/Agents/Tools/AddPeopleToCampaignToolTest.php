<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Campaigns\Enums\CampaignStatusEnum;
use Kanvas\Guild\Campaigns\Models\Campaign;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\AddPeopleToCampaignTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class AddPeopleToCampaignToolTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'ecosystem'];

    private function scheduledCampaign(Companies $company): Campaign
    {
        $campaign = new Campaign();
        $campaign->apps_id = app(Apps::class)->getId();
        $campaign->companies_id = $company->getId();
        $campaign->users_id = auth()->user()->getId();
        $campaign->channel = 'email';
        $campaign->message = 'Batch body';
        $campaign->status = CampaignStatusEnum::SCHEDULED->value;
        $campaign->scheduled_at = now()->addHour();
        $campaign->total_recipients = 0;
        $campaign->saveOrFail();

        return $campaign;
    }

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

    private function tool(Companies $company, ?Users $user = null): AddPeopleToCampaignTool
    {
        return new AddPeopleToCampaignTool()->withContext(app(Apps::class), $company, $user ?? auth()->user());
    }

    public function testAddsEligiblePeopleToAScheduledCampaign(): void
    {
        $company = Companies::factory()->create();
        $campaign = $this->scheduledCampaign($company);

        $eligible = $this->freshPerson($company);
        $eligible->addEmail('eligible@example.com');

        $noContact = $this->freshPerson($company);

        $result = $this->tool($company)->__invoke(
            campaign_id: $campaign->getId(),
            people_ids: [$eligible->getId(), $noContact->getId()],
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['added']);
        $this->assertCount(1, $result['excluded']);
        $this->assertSame(1, $campaign->refresh()->total_recipients);
        $this->assertSame($eligible->getId(), $campaign->recipients()->first()->peoples_id);
    }

    public function testRejectsAddingToACampaignThatIsAlreadySent(): void
    {
        $company = Companies::factory()->create();
        $campaign = $this->scheduledCampaign($company);
        $campaign->status = CampaignStatusEnum::SENT->value;
        $campaign->saveOrFail();

        $eligible = $this->freshPerson($company);
        $eligible->addEmail('eligible@example.com');

        $result = $this->tool($company)->__invoke(campaign_id: $campaign->getId(), people_ids: [$eligible->getId()]);

        $this->assertSame('error', $result['status']);
        $this->assertSame(0, $campaign->recipients()->count());
    }

    public function testPeopleFromAnotherCompanyAreNotFoundNotLeaked(): void
    {
        $company = Companies::factory()->create();
        $otherCompany = Companies::factory()->create();
        $campaign = $this->scheduledCampaign($company);

        $foreignPerson = $this->freshPerson($otherCompany);
        $foreignPerson->addEmail('foreign@example.com');

        $result = $this->tool($company)->__invoke(campaign_id: $campaign->getId(), people_ids: [$foreignPerson->getId()]);

        $this->assertSame('error', $result['status']);
        $this->assertSame(0, $campaign->recipients()->count());
    }

    public function testCampaignFromAnotherCompanyIsNotFound(): void
    {
        $company = Companies::factory()->create();
        $otherCompany = Companies::factory()->create();
        $campaign = $this->scheduledCampaign($otherCompany);

        $person = $this->freshPerson($company);
        $person->addEmail('person@example.com');

        $result = $this->tool($company)->__invoke(campaign_id: $campaign->getId(), people_ids: [$person->getId()]);

        $this->assertSame('error', $result['status']);
    }

    public function testNonAdminIsRejected(): void
    {
        // A bare factory user has no roles → isAdmin() is false.
        $nonAdmin = Users::factory()->create();
        $company = Companies::factory()->create();
        $campaign = $this->scheduledCampaign($company);

        $result = $this->tool($company, $nonAdmin)->__invoke(campaign_id: $campaign->getId(), people_ids: [1]);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('administrator', $result['message']);
    }
}
