<?php

declare(strict_types=1);

namespace Tests\Guild\Campaigns\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Campaigns\Actions\AddRecipientsToCampaignAction;
use Kanvas\Guild\Campaigns\Enums\CampaignRecipientStatusEnum;
use Kanvas\Guild\Campaigns\Enums\CampaignStatusEnum;
use Kanvas\Guild\Campaigns\Models\Campaign;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

class AddRecipientsToCampaignActionTest extends TestCase
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

    private function person(Companies $company): People
    {
        return People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->withUserId(auth()->user()->getId())
            ->create();
    }

    public function testAddsNewRecipientsToAScheduledCampaign(): void
    {
        $company = Companies::factory()->create();
        $campaign = $this->scheduledCampaign($company);
        $person = $this->person($company);

        $result = new AddRecipientsToCampaignAction($campaign)->execute([
            ['people_id' => $person->getId()],
        ]);

        $this->assertSame(1, $result['added']);
        $this->assertSame([], $result['already_in_campaign']);
        $this->assertSame(1, $campaign->refresh()->total_recipients);
        $this->assertSame(
            CampaignRecipientStatusEnum::PENDING->value,
            $campaign->recipients()->where('peoples_id', $person->getId())->first()->status,
        );
    }

    public function testSkipsAPersonAlreadyInTheCampaignWithoutDuplicating(): void
    {
        $company = Companies::factory()->create();
        $campaign = $this->scheduledCampaign($company);
        $person = $this->person($company);

        new AddRecipientsToCampaignAction($campaign)->execute([['people_id' => $person->getId()]]);
        $result = new AddRecipientsToCampaignAction($campaign)->execute([['people_id' => $person->getId()]]);

        $this->assertSame(0, $result['added']);
        $this->assertSame([$person->getId()], $result['already_in_campaign']);
        $this->assertSame(1, $campaign->recipients()->where('peoples_id', $person->getId())->count());
    }

    public function testRejectsAddingToACampaignThatIsAlreadySending(): void
    {
        $company = Companies::factory()->create();
        $campaign = $this->scheduledCampaign($company);
        $campaign->status = CampaignStatusEnum::SENDING->value;
        $campaign->saveOrFail();

        $this->expectException(ValidationException::class);

        new AddRecipientsToCampaignAction($campaign)->execute([
            ['people_id' => $this->person($company)->getId()],
        ]);
    }

    public function testRejectsAddingToACampaignWhoseScheduledTimeHasAlreadyPassed(): void
    {
        $company = Companies::factory()->create();
        $campaign = $this->scheduledCampaign($company);
        $campaign->scheduled_at = now()->subMinute();
        $campaign->saveOrFail();

        $this->expectException(ValidationException::class);

        new AddRecipientsToCampaignAction($campaign)->execute([
            ['people_id' => $this->person($company)->getId()],
        ]);
    }
}
