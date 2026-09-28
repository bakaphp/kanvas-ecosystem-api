<?php

declare(strict_types=1);

namespace Tests\Guild\Campaigns;

use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Campaigns\Enums\CampaignRecipientStatusEnum;
use Kanvas\Guild\Campaigns\Enums\CampaignStatusEnum;
use Kanvas\Guild\Campaigns\Jobs\ProcessLeadCampaignJob;
use Kanvas\Guild\Campaigns\Models\Campaign;
use Kanvas\Guild\Campaigns\Models\CampaignRecipient;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Tests\TestCase;

class ProcessLeadCampaignJobTest extends TestCase
{
    private function campaignFor(Companies $company, string $channel, ?string $subject = null): Campaign
    {
        $campaign = new Campaign();
        $campaign->apps_id = app(Apps::class)->getId();
        $campaign->companies_id = $company->getId();
        $campaign->users_id = auth()->user()->getId();
        $campaign->channel = $channel;
        $campaign->subject = $subject;
        $campaign->message = 'Batch body';
        $campaign->status = CampaignStatusEnum::SENDING->value;
        $campaign->total_recipients = 0;
        $campaign->saveOrFail();

        return $campaign;
    }

    private function addRecipient(Campaign $campaign, Lead $lead): CampaignRecipient
    {
        $recipient = new CampaignRecipient();
        $recipient->apps_id = $campaign->apps_id;
        $recipient->companies_id = $campaign->companies_id;
        $recipient->lead_campaigns_id = $campaign->getId();
        $recipient->leads_id = $lead->getId();
        $recipient->peoples_id = (int) $lead->people_id;
        $recipient->status = CampaignRecipientStatusEnum::PENDING->value;
        $recipient->saveOrFail();

        return $recipient;
    }

    public function testSendsToEligibleRecipientAndFinalizesCampaign(): void
    {
        Notification::fake();
        $company = Companies::factory()->create();

        $lead = Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->withUserId(auth()->user()->getId())
            ->create();
        // Clear factory-seeded contacts so the resolved destination is deterministic.
        $lead->people->contacts()->delete();
        $lead->people->addEmail('reachable@example.com');

        $campaign = $this->campaignFor($company, 'email', 'Hello');
        $recipient = $this->addRecipient($campaign, $lead);

        new ProcessLeadCampaignJob(app(Apps::class), $campaign)->handle();

        $this->assertSame(CampaignRecipientStatusEnum::SENT->value, $recipient->refresh()->status);
        $this->assertSame('reachable@example.com', $recipient->destination);
        $campaign->refresh();
        $this->assertSame(1, $campaign->sent_count);
        $this->assertSame(CampaignStatusEnum::SENT->value, $campaign->status);

        // Per-customer audit note lands in the lead's timeline, attributed to the manager (human),
        // not the AI — sender_type 'user', body carries the campaign message.
        $note = Message::query()
            ->where('companies_id', $company->getId())
            ->where('sender_type', 'user')
            ->whereJsonContains('message->from_ia', false)
            ->latest('id')
            ->first();
        $this->assertNotNull($note, 'Expected a manager-attributed audit note for the batch send');
        $this->assertStringContainsString('Batch body', $note->contentText());
    }

    public function testSkipsRecipientFlaggedDoNotContact(): void
    {
        Notification::fake();
        $company = Companies::factory()->create();

        $lead = Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->withUserId(auth()->user()->getId())
            ->create();
        $lead->people->addEmail('blocked@example.com');
        $lead->set('do_not_contact', 1);

        $campaign = $this->campaignFor($company, 'email');
        $recipient = $this->addRecipient($campaign, $lead);

        new ProcessLeadCampaignJob(app(Apps::class), $campaign)->handle();

        $this->assertSame(CampaignRecipientStatusEnum::SKIPPED->value, $recipient->refresh()->status);
        $this->assertSame('do_not_contact', $recipient->reason);
        $this->assertSame(1, $campaign->refresh()->skipped_count);
        Notification::assertNothingSent();
    }
}
