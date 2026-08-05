<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Campaigns\Enums\CampaignStatusEnum;
use Kanvas\Guild\Campaigns\Models\Campaign;
use Kanvas\Guild\Campaigns\Models\CampaignRecipient;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetBatchHistoryTool;
use Tests\TestCase;

class GetBatchHistoryToolTest extends TestCase
{
    private function campaign(Companies $company): Campaign
    {
        $campaign = new Campaign();
        $campaign->apps_id = app(Apps::class)->getId();
        $campaign->companies_id = $company->getId();
        $campaign->users_id = auth()->user()->getId();
        $campaign->channel = 'sms';
        $campaign->message = 'Body';
        $campaign->status = CampaignStatusEnum::SENT->value;
        $campaign->total_recipients = 1;
        $campaign->sent_count = 0;
        $campaign->failed_count = 1;
        $campaign->saveOrFail();

        return $campaign;
    }

    private function tool(Companies $company): GetBatchHistoryTool
    {
        return new GetBatchHistoryTool()->withContext(app(Apps::class), $company, auth()->user());
    }

    public function testListsRecentCampaigns(): void
    {
        $company = Companies::factory()->create();
        $this->campaign($company);

        $result = $this->tool($company)();

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['count']);
        $this->assertSame('sms', $result['campaigns'][0]['channel']);
    }

    public function testCampaignDetailIncludesProblemRecipients(): void
    {
        $company = Companies::factory()->create();
        $campaign = $this->campaign($company);

        $recipient = new CampaignRecipient();
        $recipient->apps_id = $campaign->apps_id;
        $recipient->companies_id = $campaign->companies_id;
        $recipient->lead_campaigns_id = $campaign->getId();
        $recipient->leads_id = 999999;
        $recipient->status = 'failed';
        $recipient->reason = 'no_deliverable_contact';
        $recipient->saveOrFail();

        $result = $this->tool($company)(campaign_id: $campaign->getId());

        $this->assertSame('success', $result['status']);
        $this->assertSame($campaign->getId(), $result['campaign']['campaign_id']);
        $this->assertSame('no_deliverable_contact', $result['problem_recipients_sample'][0]['reason']);
    }
}
