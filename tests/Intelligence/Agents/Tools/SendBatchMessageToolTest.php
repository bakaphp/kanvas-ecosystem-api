<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Campaigns\Jobs\ProcessLeadCampaignJob;
use Kanvas\Guild\Campaigns\Models\Campaign;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SendBatchMessageTool;
use Tests\TestCase;

class SendBatchMessageToolTest extends TestCase
{
    private function freshLead(Companies $company): Lead
    {
        $lead = Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->create();
        $lead->people->contacts()->whereIn('contacts_types_id', Contact::PHONE_TYPES)->delete();

        return $lead;
    }

    private function tool(Companies $company): SendBatchMessageTool
    {
        return new SendBatchMessageTool()->withContext(app(Apps::class), $company, auth()->user());
    }

    public function testCreatesCampaignForEligibleOnlyAndQueuesTheJob(): void
    {
        Queue::fake();
        $company = Companies::factory()->create();

        $eligible = $this->freshLead($company);
        $eligible->people->addPhone('+13055551111');
        $doNotContact = $this->freshLead($company);
        $doNotContact->people->addPhone('+13055552222');
        $doNotContact->set('do_not_contact', 1);

        $result = $this->tool($company)(
            lead_ids: $eligible->getId() . ',' . $doNotContact->getId(),
            channel: 'sms',
            message: 'Hello from the team',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['recipients_queued']);
        $this->assertSame(1, $result['excluded_count']);

        $campaign = Campaign::findOrFail($result['campaign_id']);
        $this->assertSame('sending', $campaign->status);
        $this->assertSame(1, $campaign->total_recipients);
        $this->assertSame(1, $campaign->recipients()->count());
        $this->assertSame($eligible->getId(), (int) $campaign->recipients()->first()->leads_id);

        Queue::assertPushed(ProcessLeadCampaignJob::class);
    }

    public function testSchedulingSetsScheduledStatus(): void
    {
        Queue::fake();
        $company = Companies::factory()->create();
        $lead = $this->freshLead($company);
        $lead->people->addPhone('+13055553333');

        $result = $this->tool($company)(
            lead_ids: (string) $lead->getId(),
            channel: 'sms',
            message: 'Later',
            schedule_at: now()->addDay()->toDateTimeString(),
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame('scheduled', $result['campaign_status']);
        $this->assertNotNull($result['scheduled_at']);
        Queue::assertPushed(ProcessLeadCampaignJob::class);
    }

    public function testNoEligibleRecipientsSendsNothing(): void
    {
        Queue::fake();
        $company = Companies::factory()->create();
        $noContact = $this->freshLead($company); // no phone

        $result = $this->tool($company)(
            lead_ids: (string) $noContact->getId(),
            channel: 'sms',
            message: 'Hi',
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame(0, Campaign::query()->where('companies_id', $company->getId())->count());
        Queue::assertNotPushed(ProcessLeadCampaignJob::class);
    }

    public function testInvalidChannelReturnsError(): void
    {
        $result = $this->tool(auth()->user()->getCurrentCompany())(
            lead_ids: '1',
            channel: 'pigeon',
            message: 'Hi',
        );

        $this->assertSame('error', $result['status']);
    }
}
