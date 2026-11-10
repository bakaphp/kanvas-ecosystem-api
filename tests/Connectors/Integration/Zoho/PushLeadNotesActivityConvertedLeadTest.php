<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Closure;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Activities\PushLeadNotesActivity;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\Handlers\ZohoHandler;
use Kanvas\Connectors\Zoho\ZohoService;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Integration\Zoho\Concerns\BuildsGetDocsMessage;
use Tests\Connectors\Integration\Zoho\Concerns\BuildsZohoDeal;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;
use Webleit\ZohoCrmApi\Modules\Records as ZohoRecordsModule;

/**
 * Which Zoho records a Get Docs submission on a lead is uploaded to. The pull itself is covered by
 * PullConvertedLeadDealActionTest; here it is stubbed to the deal it would return.
 */
final class PushLeadNotesActivityConvertedLeadTest extends TestCase
{
    use BuildsGetDocsMessage;
    use BuildsZohoDeal;
    use DatabaseTransactions;
    use HasIntegrationCompany;

    protected $connectionsToTransact = [null, 'crm', 'social', 'ecosystem', 'workflow'];

    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeZohoCompany();
        $this->setIntegration(
            app(Apps::class),
            IntegrationsEnum::ZOHO,
            ZohoHandler::class,
            $this->company,
            auth()->user()
        );
    }

    public function testAConvertedLeadSendsTheFilesToItsPulledDealOnly(): void
    {
        $this->company->set(CustomFieldEnum::ZOHO_GET_DOCS_LEAD_STATUS->value, 'Hot');
        $lead = $this->zohoLead();
        $activity = $this->activity(pulledZohoDealId: 'zd-1');

        $result = $activity->execute($this->getDocsMessage($lead, $this->company), app(Apps::class), []);

        $this->assertSame(['zd-1'], $activity->targetIds);
        $this->assertTrue($activity->pullAttempted);
        $this->assertSame('lead_converted', $result['leadStatus']['reason']);
    }

    public function testALeadThatAlreadyHasItsDealIsNotLookedUpInZoho(): void
    {
        $lead = $this->zohoLead();
        $this->makeDeal($this->company, lead: $lead)->set(CustomFieldEnum::ZOHO_DEAL_ID->value, 'zd-2');
        $activity = $this->activity(pulledZohoDealId: null);

        $activity->execute($this->getDocsMessage($lead, $this->company), app(Apps::class), []);

        $this->assertFalse($activity->pullAttempted);
        $this->assertSame(['zl-1', 'zd-2'], $activity->targetIds);
    }

    public function testALeadZohoStillHoldsAsALeadIsTheOnlyTarget(): void
    {
        $lead = $this->zohoLead();
        $activity = $this->activity(pulledZohoDealId: null);

        $result = $activity->execute($this->getDocsMessage($lead, $this->company), app(Apps::class), []);

        $this->assertTrue($activity->pullAttempted);
        $this->assertSame(['zl-1'], $activity->targetIds);
        $this->assertSame('not_configured', $result['leadStatus']['reason']);
    }

    private function zohoLead(): Lead
    {
        $lead = $this->makeLead($this->company);
        $lead->set(CustomFieldEnum::ZOHO_LEAD_ID->value, 'zl-1');

        return $lead;
    }

    private function activity(?string $pulledZohoDealId): PushLeadNotesActivity
    {
        $activity = new class (
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        ) extends PushLeadNotesActivity {
            public ?string $pulledZohoDealId = null;
            public bool $pullAttempted = false;
            /** @var list<string> */
            public array $targetIds = [];
            public ?ZohoService $fakeZohoService = null;
            public ?Closure $makeDeal = null;

            protected function zohoService(Lead $lead): ZohoService
            {
                return $this->fakeZohoService;
            }

            protected function pullConvertedLeadDeal(Lead $lead, ZohoService $zohoService): ?Deal
            {
                $this->pullAttempted = true;

                if ($this->pulledZohoDealId === null) {
                    return null;
                }

                $lead->set(CustomFieldEnum::ZOHO_LEAD_CONVERTED_DETAIL->value, ['deal' => $this->pulledZohoDealId]);
                $deal = ($this->makeDeal)($lead);
                $deal->set(CustomFieldEnum::ZOHO_DEAL_ID->value, $this->pulledZohoDealId);

                return $deal;
            }

            protected function pushGetDocs(Message $message, callable $resolveTargets): array
            {
                $this->targetIds = array_map(strval(...), array_keys($resolveTargets()));

                return ['files' => 1];
            }
        };

        $zoho = $this->createStub(ZohoService::class);
        $zoho->method('getLeadModule')->willReturn($this->createStub(ZohoRecordsModule::class));
        $zoho->method('getDealModule')->willReturn($this->createStub(ZohoRecordsModule::class));

        $activity->pulledZohoDealId = $pulledZohoDealId;
        $activity->fakeZohoService = $zoho;
        $activity->makeDeal = fn (Lead $lead): Deal => $this->makeDeal($this->company, lead: $lead);

        return $activity;
    }
}
