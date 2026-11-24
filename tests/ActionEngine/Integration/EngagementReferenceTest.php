<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Integration;

use Baka\Support\Str;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Actions\Models\CompanyActionVisitor;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as EngagementData;
use Kanvas\ActionEngine\Engagements\Events\EngagementCompanyUpdateEvent;
use Kanvas\ActionEngine\Engagements\Events\EngagementCompletedEvent;
use Kanvas\ActionEngine\Engagements\Events\EngagementStatusChangedEvent;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Engagements\Support\EngagementReferenceMap;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\Support\Setup;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use PHPUnit\Framework\Attributes\Group;
use Tests\ActionEngine\Fixtures\EngageablePeople;
use Tests\TestCase;

/**
 * Serial: Setup writes the global `companies_id = 0` actions row every process shares, and the
 * link test sets a company setting (Redis) for the duration of one test.
 */
#[Group('serial')]
final class EngagementReferenceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'action_engine', 'crm', 'social'];

    private Apps $currentApp;
    private Companies $company;
    private Lead $lead;
    private People $people;
    private EngageablePeople $reference;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->company = auth()->user()->getCurrentCompany();

        Queue::fake();
        Event::fake([
            EngagementCompletedEvent::class,
            EngagementStatusChangedEvent::class,
            EngagementCompanyUpdateEvent::class,
        ]);

        EngagementReferenceMap::register(EngageablePeople::ALIAS, EngageablePeople::class);

        $this->lead = Lead::factory()
            ->withAppAndCompany($this->currentApp->getId(), $this->company->getId())
            ->create();
        $this->lead->companies_branches_id = $this->company->defaultBranch()->firstOrFail()->getId();
        $this->lead->saveOrFail();

        $this->people = $this->lead->people;
        $this->reference = EngageablePeople::query()->findOrFail($this->people->getId());

        new Setup($this->currentApp, auth()->user(), $this->company)->run();
    }

    protected function tearDown(): void
    {
        EngagementReferenceMap::forget(EngageablePeople::ALIAS);

        parent::tearDown();
    }

    public function testReferencePathCreatesMessageVisitorChannelAndEngagementWithNoLead(): void
    {
        $requestId = Str::uuid()->toString();
        $action = $this->recordingAction($this->referenceData($requestId));
        $engagement = $action->execute();

        $this->assertNull($engagement->leads_id);
        $this->assertSame(EngageablePeople::ALIAS, $engagement->entity_type);
        $this->assertSame($this->reference->getId(), (int) $engagement->entity_id);
        $this->assertSame($this->people->getId(), (int) $engagement->people_id);
        $this->assertSame($requestId, $engagement->entity_uuid);
        $this->assertSame(ActionStatusEnum::SENT->value, $engagement->stage->slug);

        $message = $engagement->message;
        $this->assertSame(EngageablePeople::class, $message->appModuleMessage->system_modules);
        $this->assertSame($this->reference->getId(), (int) $message->appModuleMessage->entity_id);

        $slug = Str::sanitizeChannelName(EngageablePeople::ALIAS . '-' . $this->reference->uuid);
        $channel = Channel::query()->where('slug', $slug)->where('apps_id', $this->currentApp->getId())->firstOrFail();
        $this->assertSame(EngageablePeople::class, $channel->entity_namespace);
        $this->assertTrue($channel->messages()->where('messages.id', $message->getId())->exists());

        $visitor = CompanyActionVisitor::query()->where('visitors_id', $requestId)->firstOrFail();
        $this->assertSame('', $visitor->leads_id);
        $this->assertSame(EngageablePeople::ALIAS, $visitor->request['entity_type']);
        $this->assertSame($this->reference->getId(), (int) $visitor->request['entity_id']);

        $this->assertSame(EngageablePeople::ALIAS, $action->workflowParams['entity_type']);
        $this->assertInstanceOf(Message::class, $action->workflowParams['message']);

        $this->assertInstanceOf(EngageablePeople::class, $engagement->reference);
        $this->assertSame($this->reference->getId(), $engagement->reference->getId());
        $this->assertTrue($engagement->hasNonLeadReference());

        Event::assertNotDispatched(EngagementStatusChangedEvent::class);
    }

    public function testReferencePathGetsTheActionPageLinkFromTheEngagementUuid(): void
    {
        $this->company->set('new_action_page_v4_config', [ActionEnum::SYNC->value => 'invoice']);

        try {
            $engagement = $this->recordingAction($this->referenceData(Str::uuid()->toString()))->execute();
        } finally {
            $this->company->del('new_action_page_v4_config');
        }

        $this->assertNotEmpty($engagement->message->getMessage()['action_link'] ?? null);
    }

    public function testLeadPathDualWritesTheLeadAsReferenceAndStillDispatchesTheLeadEvent(): void
    {
        $engagement = $this->recordingAction($this->leadData())->execute();

        $this->assertSame($this->lead->getId(), (int) $engagement->leads_id);
        $this->assertSame(EngagementReferenceMap::LEAD, $engagement->entity_type);
        $this->assertSame($this->lead->getId(), (int) $engagement->entity_id);
        $this->assertTrue($engagement->referencesLead());
        $this->assertFalse($engagement->hasNonLeadReference());
        Event::assertDispatched(EngagementStatusChangedEvent::class);
    }

    public function testNonLeadDuplicateCheckReusesTheOpenEngagementOfTheSameReference(): void
    {
        $requestId = Str::uuid()->toString();
        $first = new CreateEngagementAction($this->referenceData($requestId), allowDuplicate: false)->withoutWorkflow()->execute();
        $second = new CreateEngagementAction($this->referenceData($requestId), allowDuplicate: false)->withoutWorkflow()->execute();

        $this->assertSame($first->getId(), $second->getId());
    }

    /**
     * GeneratesChecklistEngagementUrls and CreateMessageFollowUpAction both create with
     * allowDuplicate: false, which reuses the person's open engagement on the action. An open
     * non-lead engagement for the same person, user and action must never be the one reused.
     */
    public function testLeadDuplicateCheckNeverReusesANonLeadEngagement(): void
    {
        $nonLead = new CreateEngagementAction($this->referenceData(Str::uuid()->toString()))->withoutWorkflow()->execute();

        $leadEngagement = new CreateEngagementAction($this->leadData(), allowDuplicate: false)->withoutWorkflow()->execute();

        $this->assertNotSame($nonLead->getId(), $leadEngagement->getId());
        $this->assertSame($this->lead->getId(), (int) $leadEngagement->leads_id);

        $again = new CreateEngagementAction($this->leadData(), allowDuplicate: false)->withoutWorkflow()->execute();
        $this->assertSame($leadEngagement->getId(), $again->getId());
    }

    public function testReferenceInAnotherCompanyIsRejected(): void
    {
        $otherCompany = Companies::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        new CreateEngagementAction(new EngagementData(
            app: $this->currentApp,
            company: $otherCompany,
            user: auth()->user(),
            lead: null,
            action: ActionEnum::SYNC->value,
            requestId: Str::uuid()->toString(),
            source: 'test',
            status: ActionStatusEnum::SENT,
            people: $this->people,
            reference: $this->reference,
        ));
    }

    public function testPersonInAnotherCompanyIsRejected(): void
    {
        $outsider = People::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId(Companies::factory()->create()->getId())
            ->create();

        $this->expectException(InvalidArgumentException::class);

        new CreateEngagementAction(EngagementData::from(
            auth()->user(),
            $outsider,
            $this->reference,
            $this->request(Str::uuid()->toString())
        ));
    }

    public function testANonLeadEngagementRequiresAPerson(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An engagement needs a person.');

        new CreateEngagementAction(new EngagementData(
            app: $this->currentApp,
            company: $this->company,
            user: auth()->user(),
            lead: null,
            action: ActionEnum::SYNC->value,
            requestId: Str::uuid()->toString(),
            source: 'test',
            status: ActionStatusEnum::SENT,
            reference: $this->reference,
        ));
    }

    public function testSpatieRoutesEachShapeToItsOwnFactory(): void
    {
        $byReference = EngagementData::from(
            auth()->user(),
            $this->people,
            $this->reference,
            $this->request('r-1')
        );
        $this->assertNull($byReference->lead);
        $this->assertSame($this->reference, $byReference->getReference());
        $this->assertSame($this->company->getId(), $byReference->company->getId());

        $positionalLead = EngagementData::from(
            $this->currentApp,
            $this->company,
            auth()->user(),
            $this->lead,
            $this->request('r-2')
        );
        $this->assertSame($this->lead, $positionalLead->lead);
        $this->assertNull($positionalLead->reference);
        $this->assertSame($this->lead, $positionalLead->getReference());

        // The named shape ElevenLabs and CreateMessageFollowUpAction use.
        $namedLead = EngagementData::from(
            app: $this->currentApp,
            company: $this->company,
            user: auth()->user(),
            lead: $this->lead,
            request: $this->request('r-3'),
            people: $this->people,
        );
        $this->assertSame($this->lead, $namedLead->lead);
        $this->assertSame('r-3', $namedLead->requestId);
    }

    public function testAnUnregisteredAliasIsRejectedOnSave(): void
    {
        $engagement = $this->recordingAction($this->leadData())->execute();
        $engagement->entity_type = 'not_registered';

        $this->expectException(InvalidArgumentException::class);
        $engagement->save();
    }

    public function testAReferenceTypeWithoutAnIdIsRejectedOnSave(): void
    {
        $engagement = $this->recordingAction($this->leadData())->execute();
        $engagement->entity_id = null;

        $this->expectException(InvalidArgumentException::class);
        $engagement->save();
    }

    public function testALegacyRowResolvesItsLeadLazilyAndEagerly(): void
    {
        $engagement = $this->recordingAction($this->leadData())->execute();
        DB::connection('action_engine')->table('engagements')
            ->where('id', $engagement->getId())
            ->update(['entity_type' => null, 'entity_id' => null]);

        $legacy = Engagement::query()->findOrFail($engagement->getId());
        $this->assertNull($legacy->entity_type);
        $this->assertInstanceOf(Lead::class, $legacy->reference);
        $this->assertSame($this->lead->getId(), $legacy->reference->getId());
        $this->assertTrue($legacy->referencesLead());
        $this->assertFalse($legacy->hasNonLeadReference());

        $nonLead = $this->recordingAction($this->referenceData(Str::uuid()->toString()))->execute();
        $eager = Engagement::query()
            ->whereIn('id', [$engagement->getId(), $nonLead->getId()])
            ->with('reference')
            ->get()
            ->keyBy('id');

        $this->assertInstanceOf(Lead::class, $eager[$engagement->getId()]->getRelation('reference'));
        $this->assertInstanceOf(EngageablePeople::class, $eager[$nonLead->getId()]->getRelation('reference'));
    }

    public function testWhereReferenceMatchesNewAndLegacyLeadRowsAndNonLeadRows(): void
    {
        $leadEngagement = $this->recordingAction($this->leadData())->execute();
        $legacy = $this->recordingAction($this->leadData())->execute();
        DB::connection('action_engine')->table('engagements')
            ->where('id', $legacy->getId())
            ->update(['entity_type' => null, 'entity_id' => null]);
        $nonLead = $this->recordingAction($this->referenceData(Str::uuid()->toString()))->execute();

        $leadIds = Engagement::query()->whereReference($this->lead)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$leadEngagement->getId(), $legacy->getId()], $leadIds);

        $this->assertSame(
            [$nonLead->getId()],
            Engagement::query()->whereReference($this->reference)->pluck('id')->all()
        );
    }

    public function testDeclinedIsAValidEngagementStatus(): void
    {
        $this->assertTrue(ActionStatusEnum::validate('declined'));
        $this->assertSame(ActionStatusEnum::DECLINED, ActionStatusEnum::from('declined'));
    }

    private function recordingAction(EngagementData $data): CreateEngagementAction
    {
        return new class ($data) extends CreateEngagementAction {
            public array $workflowParams = [];

            protected function fireCreatedWorkflow(Engagement $engagement, Message $message, Channel $channel): void
            {
                $this->workflowParams = $this->createdWorkflowParams($engagement, $message, $channel);
            }
        };
    }

    private function referenceData(string $requestId): EngagementData
    {
        return EngagementData::from(
            auth()->user(),
            $this->people,
            $this->reference,
            $this->request($requestId)
        );
    }

    private function leadData(): EngagementData
    {
        return EngagementData::from(
            $this->currentApp,
            $this->company,
            auth()->user(),
            $this->lead,
            $this->request(Str::uuid()->toString())
        );
    }

    private function request(string $requestId): array
    {
        return [
            'action' => ActionEnum::SYNC->value,
            'request_id' => $requestId,
            'source' => 'test',
            'status' => ActionStatusEnum::SENT->value,
            'data' => [],
        ];
    }
}
