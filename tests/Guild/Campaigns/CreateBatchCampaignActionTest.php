<?php

declare(strict_types=1);

namespace Tests\Guild\Campaigns;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Campaigns\Actions\CreateBatchCampaignAction;
use Kanvas\Guild\Campaigns\Models\CampaignRecipient;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

class CreateBatchCampaignActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'ecosystem'];

    private function person(Companies $company): People
    {
        return People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->withUserId(auth()->user()->getId())
            ->create();
    }

    public function testPerRecipientAttachmentsAndVariablesAreStoredOnTheRecipient(): void
    {
        Queue::fake();
        $company = Companies::factory()->create();
        $withExtras = $this->person($company);
        $plain = $this->person($company);

        $campaign = new CreateBatchCampaignAction(
            app: app(Apps::class),
            company: $company,
            user: auth()->user(),
            channel: 'email',
            message: 'Hello',
            subject: 'Subject',
            eligibleRecipients: [
                [
                    'people_id' => $withExtras->getId(),
                    'attachment_urls' => ['https://example.test/PA1.pdf'],
                    'variables' => ['Saludo' => 'Hola'],
                ],
                ['people_id' => $plain->getId()],
            ],
        )->execute();

        $extras = CampaignRecipient::query()
            ->where('lead_campaigns_id', $campaign->getId())
            ->where('peoples_id', $withExtras->getId())
            ->firstOrFail();
        $this->assertSame(['https://example.test/PA1.pdf'], $extras->attachment_urls);
        $this->assertSame(['Saludo' => 'Hola'], $extras->variables);

        $bare = CampaignRecipient::query()
            ->where('lead_campaigns_id', $campaign->getId())
            ->where('peoples_id', $plain->getId())
            ->firstOrFail();
        $this->assertNull($bare->attachment_urls);
        $this->assertNull($bare->variables);
    }
}
