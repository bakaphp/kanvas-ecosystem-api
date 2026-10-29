<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Campaigns\Enums\CampaignRecipientStatusEnum;
use Kanvas\Guild\Campaigns\Enums\CampaignStatusEnum;
use Kanvas\Guild\Campaigns\Models\Campaign;
use Kanvas\Guild\Campaigns\Models\CampaignRecipient;
use Tests\TestCase;

class LeadCampaignTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = [null, 'crm'];

    private function campaign(Companies $company, string $subject): Campaign
    {
        $campaign = new Campaign();
        $campaign->apps_id = app(Apps::class)->getId();
        $campaign->companies_id = $company->getId();
        $campaign->users_id = auth()->user()->getId();
        $campaign->channel = 'email';
        $campaign->subject = $subject;
        $campaign->message = 'Batch body';
        $campaign->status = CampaignStatusEnum::SENT->value;
        $campaign->total_recipients = 2;
        $campaign->sent_count = 2;
        $campaign->opened_count = 1;
        $campaign->saveOrFail();

        return $campaign;
    }

    public function testListsTheCompanysCampaignsWithTheirRecipientsAndOpens(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $campaign = $this->campaign($company, 'Mine ' . uniqid());

        $opened = CampaignRecipient::pending($campaign, leadId: null, peopleId: null);
        $opened->status = CampaignRecipientStatusEnum::OPENED->value;
        $opened->destination = 'opened@example.com';
        $opened->sent_at = Carbon::now();
        $opened->opened_at = Carbon::now();
        $opened->open_count = 2;
        $opened->saveOrFail();

        $unopened = CampaignRecipient::pending($campaign, leadId: null, peopleId: null);
        $unopened->status = CampaignRecipientStatusEnum::SENT->value;
        $unopened->destination = 'unopened@example.com';
        $unopened->saveOrFail();

        $response = $this->graphQL('
            query($id: Mixed) {
                leadCampaigns(where: { column: ID, operator: EQ, value: $id }) {
                    data {
                        id
                        subject
                        sent_count
                        opened_count
                        recipients(where: { column: STATUS, operator: EQ, value: "opened" }) {
                            data { destination status opened_at open_count }
                            paginatorInfo { total }
                        }
                    }
                }
            }
        ', ['id' => $campaign->getId()])->assertSuccessful();

        $data = $response->json('data.leadCampaigns.data');
        $this->assertCount(1, $data, json_encode($response->json()));
        $this->assertSame($campaign->subject, $data[0]['subject']);
        $this->assertSame(1, $data[0]['opened_count']);
        $this->assertSame(1, $data[0]['recipients']['paginatorInfo']['total']);
        $this->assertSame('opened@example.com', $data[0]['recipients']['data'][0]['destination']);
        $this->assertSame(2, $data[0]['recipients']['data'][0]['open_count']);
        $this->assertNotNull($data[0]['recipients']['data'][0]['opened_at']);
    }

    public function testAnotherCompanysCampaignIsNotListed(): void
    {
        $other = $this->campaign(Companies::factory()->create(), 'Theirs ' . uniqid());

        $response = $this->graphQL('
            query($id: Mixed) {
                leadCampaigns(where: { column: ID, operator: EQ, value: $id }) {
                    data { id }
                }
            }
        ', ['id' => $other->getId()])->assertSuccessful();

        $this->assertSame([], $response->json('data.leadCampaigns.data'), json_encode($response->json()));
    }
}
