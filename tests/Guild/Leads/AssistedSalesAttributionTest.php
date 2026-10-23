<?php

declare(strict_types=1);

namespace Tests\Guild\Leads;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;
use Kanvas\Analytics\Actions\BuildAssistedSalesAttributionAction;
use Kanvas\Analytics\DataTransferObject\AnalyticsRequest;
use Kanvas\Analytics\Enums\AnalyticsBucketEnum;
use Kanvas\Analytics\Enums\AttributionPopulationEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetAssistedSalesAttributionTool;
use Kanvas\Social\Messages\Models\AppModuleMessage;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class AssistedSalesAttributionTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Leads on crm, messages on social, engagements on action_engine, the ledger on intelligence.
     */
    protected array $connectionsToTransact = ['mysql', 'crm', 'social', 'action_engine', 'intelligence'];

    private Apps $kanvasApp;
    private Companies $company;
    private Users $user;
    private LeadStatus $sold;
    private MessageType $smsType;

    protected function setUp(): void
    {
        parent::setUp();

        // A lead closes after its touches in real life; here everything is seeded within the same
        // second or two, so freeze the clock or a touch can land a tick after `closed_at`.
        Carbon::setTestNow(Carbon::now()->startOfSecond());

        $this->kanvasApp = app(Apps::class);
        /** @var Users $authedUser */
        $authedUser = auth()->user();
        $this->user = $authedUser;

        // The report counts every sold lead the company has in the window, so it needs a company
        // no parallel test writes to.
        $this->company = Companies::factory()->create(['users_id' => $this->user->getId()]);
        $this->company->associateApp($this->kanvasApp);

        $this->sold = LeadStatus::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'name' => 'Sold',
            'is_default' => 0,
        ]);

        $this->smsType = MessageType::factory()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'verb' => 'sms-' . fake()->unique()->lexify('????'),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testSplitsSoldLeadsByAiAndActionAssistance(): void
    {
        $aiOnly = $this->soldLead();
        $this->aiMessage($aiOnly);

        $actionOnly = $this->soldLead();
        $this->engagement($actionOnly, actionSlug: 'view-vehicle', stageSlug: 'sent');

        $both = $this->soldLead();
        $this->aiMessage($both);
        $this->engagement($both, actionSlug: 'credit-app', stageSlug: 'submitted');

        $this->soldLead();

        $result = $this->attribution();

        $this->assertSame(4, $result['sold']);
        $this->assertSame(2, $result['assisted_by_ai']['count']);
        $this->assertSame(0.5, $result['assisted_by_ai']['share']);
        $this->assertSame(2, $result['assisted_by_action']['count']);
        $this->assertSame(2, $result['assisted_by_action']['shared_action'], 'A submitted action was shared first, so it counts in both.');
        $this->assertSame(1, $result['assisted_by_action']['submitted_action']);
        $this->assertSame(3, $result['assisted_by_either']);
        $this->assertSame(1, $result['assisted_by_both']);
        $this->assertSame(1, $result['unassisted']);
        $this->assertSame(4, $result['leads']);
        $this->assertCount(4, $result['lead_rows']);
        $this->assertArrayNotHasKey('outcomes', $result, 'Every sold lead sold; there is nothing to compare.');
    }

    public function testAllPopulationTakesTheLeadsCreatedInTheWindowAndComparesOutcomes(): void
    {
        $aiSold = $this->soldLead();
        $this->aiMessage($aiSold);

        $aiOpen = $this->openLead();
        $this->aiMessage($aiOpen);

        $this->soldLead();
        $this->openLead();
        $this->openLead();

        $result = $this->attribution(AttributionPopulationEnum::ALL);

        $this->assertSame('all', $result['population']);
        $this->assertSame(5, $result['leads']);
        $this->assertSame(2, $result['sold']);
        $this->assertSame(2, $result['assisted_by_ai']['count']);
        $this->assertSame(['leads' => 2, 'sold' => 1, 'sold_rate' => 0.5], $result['outcomes']['assisted_by_ai']);
        $this->assertSame(['leads' => 3, 'sold' => 1, 'sold_rate' => 0.3333], $result['outcomes']['not_assisted_by_ai']);
        $this->assertSame(['leads' => 0, 'sold' => 0, 'sold_rate' => null], $result['outcomes']['assisted_by_action']);

        $openRow = collect($result['lead_rows'])->firstWhere('lead_id', $aiOpen->getId());
        $this->assertFalse($openRow['sold']);
        $this->assertNull($openRow['closed_at']);
        $this->assertTrue($openRow['assisted_by_ai']);
    }

    public function testOpenPopulationLeavesSoldLeadsOutAndCountsTouchesUpToNow(): void
    {
        $sold = $this->soldLead();
        $this->aiMessage($sold);

        $open = $this->openLead();
        $open->created_at = Carbon::now()->subDays(3);
        $open->save();
        $this->aiMessage($open, at: Carbon::now()->subDay());
        $this->engagement($open, actionSlug: 'get-docs', stageSlug: 'sent');

        $result = $this->attribution(AttributionPopulationEnum::OPEN);

        $this->assertSame(1, $result['leads']);
        $this->assertSame(0, $result['sold']);
        $this->assertSame(1, $result['assisted_by_ai']['count']);
        $this->assertSame(1, $result['assisted_by_action']['count']);
        $this->assertSame($open->getId(), $result['lead_rows'][0]['lead_id']);
    }

    public function testTheToolRejectsAnUnknownPopulation(): void
    {
        $result = new GetAssistedSalesAttributionTool()
            ->withContext($this->kanvasApp, $this->company, $this->user)
            ->__invoke(population: 'won');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('sold, open, all', $result['message']);
    }

    public function testOnlySoldLeadsClosedInsideTheWindowCount(): void
    {
        $this->soldLead();

        $earlier = $this->soldLead();
        $earlier->closed_at = Carbon::now()->subDays(60);
        $earlier->save();

        $this->aiMessage($this->openLead());

        $this->assertSame(1, $this->attribution()['leads']);
    }

    public function testAnAiMessageAfterTheSaleOrBeforeTheLeadExistedIsNotAssistance(): void
    {
        $lead = $this->soldLead();
        $lead->created_at = Carbon::now()->subDays(5);
        $lead->closed_at = Carbon::now()->subDays(2);
        $lead->save();

        $this->aiMessage($lead, at: Carbon::now()->subDay());
        $this->aiMessage($lead, at: Carbon::now()->subDays(9));

        $this->assertSame(0, $this->attribution()['assisted_by_ai']['count']);

        $this->aiMessage($lead, at: Carbon::now()->subDays(3));

        $this->assertSame(1, $this->attribution()['assisted_by_ai']['count']);
    }

    public function testAnAiMessageLinkedOnlyThroughThePersonStillCounts(): void
    {
        $lead = $this->soldLead();
        $this->aiMessage($lead, linkToLead: false);

        $this->assertSame(1, $this->attribution()['assisted_by_ai']['count']);
    }

    public function testAHumanRepMessageIsNotAiAssistance(): void
    {
        $lead = $this->soldLead();
        $this->message($lead, ['from_me' => true]);

        $this->assertSame(0, $this->attribution()['assisted_by_ai']['count']);
    }

    /**
     * A draft held for approval is locked and never reached the customer; approving it unlocks it,
     * rejecting it soft-deletes it. Only the first case is assistance.
     */
    public function testAnAiDraftPendingApprovalIsNotAssistanceUntilApproved(): void
    {
        $lead = $this->soldLead();
        $draft = $this->aiMessage($lead);
        $draft->setLock();

        $this->assertSame(0, $this->attribution()['assisted_by_ai']['count']);

        $draft->setUnlock();

        $this->assertSame(1, $this->attribution()['assisted_by_ai']['count']);
    }

    public function testAnySharedCatalogActionCountsButAnUnknownSlugDoesNot(): void
    {
        $shared = $this->soldLead();
        $this->engagement($shared, actionSlug: 'credit-app', stageSlug: 'sent');

        $unknown = $this->soldLead();
        $this->engagement($unknown, actionSlug: 'not-a-catalog-action-' . fake()->lexify('????'), stageSlug: 'submitted');

        $result = $this->attribution()['assisted_by_action'];

        $this->assertSame(1, $result['count']);
        $this->assertSame(1, $result['shared_action']);
        $this->assertSame(0, $result['submitted_action']);
    }

    public function testDoesNotCountAnotherCompanysSoldLeads(): void
    {
        $other = Companies::factory()->create(['users_id' => $this->user->getId()]);
        $other->associateApp($this->kanvasApp);
        $otherSold = LeadStatus::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $other->getId(),
            'name' => 'Sold',
            'is_default' => 0,
        ]);
        Lead::factory()
            ->withAppAndCompany($this->kanvasApp->getId(), $other->getId())
            ->create(['leads_status_id' => $otherSold->getId()]);

        $this->assertSame(0, $this->attribution()['sold']);
    }

    /**
     * The tool resolves the window in the company timezone; a lead closed minutes ago must be inside
     * it whatever the offset, which only holds when the range is bound in UTC.
     */
    public function testTheToolReportsThroughTheSameAction(): void
    {
        $this->company->timezone = 'Pacific/Pago_Pago';
        $this->company->save();

        $lead = $this->soldLead();
        $this->aiMessage($lead);

        $result = new GetAssistedSalesAttributionTool()
            ->withContext($this->kanvasApp, $this->company, $this->user)
            ->__invoke(timeframe: 'last_30_days');

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['sold']);
        $this->assertSame(1, $result['assisted_by_ai']['count']);
        $this->assertNotEmpty($result['note']);
    }

    /**
     * @return array<string, mixed>
     */
    private function attribution(AttributionPopulationEnum $population = AttributionPopulationEnum::SOLD): array
    {
        return new BuildAssistedSalesAttributionAction(
            app: $this->kanvasApp,
            company: $this->company,
            request: new AnalyticsRequest(
                from: Carbon::now()->subDays(29)->startOfDay(),
                to: Carbon::now()->endOfDay(),
                bucket: AnalyticsBucketEnum::DAY,
                timezone: 'UTC',
            ),
            population: $population,
        )->execute();
    }

    private function openLead(): Lead
    {
        return Lead::factory()
            ->withAppAndCompany($this->kanvasApp->getId(), $this->company->getId())
            ->create();
    }

    private function soldLead(): Lead
    {
        $lead = Lead::factory()
            ->withAppAndCompany($this->kanvasApp->getId(), $this->company->getId())
            ->create(['leads_status_id' => $this->sold->getId()]);

        $this->assertNotNull($lead->refresh()->closed_at);

        return $lead;
    }

    private function aiMessage(Lead $lead, ?Carbon $at = null, bool $linkToLead = true): Message
    {
        return $this->message($lead, ['from_me' => true, 'from_ia' => true], $at, $linkToLead);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function message(
        Lead $lead,
        array $payload,
        ?Carbon $at = null,
        bool $linkToLead = true
    ): Message {
        $message = Message::factory()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'people_id' => $lead->people_id,
            'message_types_id' => $this->smsType->getId(),
            'message' => $payload,
        ]);

        if ($at !== null) {
            Message::query()->whereKey($message->getId())->update(['created_at' => $at]);
        }

        if ($linkToLead) {
            AppModuleMessage::create([
                'message_id' => $message->getId(),
                'message_types_id' => $this->smsType->getId(),
                'apps_id' => $this->kanvasApp->getId(),
                'companies_id' => $this->company->getId(),
                'system_modules' => Lead::class,
                'entity_id' => $lead->getId(),
                'is_deleted' => 0,
            ]);
        }

        return $message;
    }

    private function engagement(Lead $lead, string $actionSlug, string $stageSlug): Engagement
    {
        $pipeline = Pipeline::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'name' => 'Pipeline ' . fake()->uuid(),
            'slug' => 'pipeline-' . fake()->uuid(),
            'weight' => 0,
        ]);

        $stage = PipelineStage::create([
            'pipelines_id' => $pipeline->getId(),
            'name' => $stageSlug,
            'slug' => $stageSlug,
            'weight' => 1,
        ]);

        $action = Action::firstOrCreate(
            ['slug' => $actionSlug],
            [
                'companies_id' => 0,
                'apps_id' => 0,
                'users_id' => 0,
                'pipelines_id' => $pipeline->getId(),
                'name' => $actionSlug,
                'is_active' => 1,
                'is_published' => 1,
                'is_deleted' => 0,
            ],
        );

        $companyAction = CompanyAction::create([
            'companies_id' => $this->company->getId(),
            'companies_branches_id' => $this->company->branch()->firstOrFail()->getId(),
            'apps_id' => $this->kanvasApp->getId(),
            'users_id' => $this->user->getId(),
            'actions_id' => $action->getId(),
            'name' => $actionSlug,
            'pipelines_id' => $pipeline->getId(),
            'is_active' => 1,
            'is_published' => 1,
            'weight' => 1,
            'is_deleted' => 0,
        ]);

        return Engagement::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'companies_actions_id' => $companyAction->getId(),
            'message_id' => 0,
            'leads_id' => $lead->getId(),
            'people_id' => $lead->people_id,
            'pipelines_stages_id' => $stage->getId(),
            'entity_uuid' => fake()->uuid(),
            'slug' => 'engagement-' . fake()->uuid(),
        ]);
    }
}
