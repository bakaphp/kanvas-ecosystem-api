<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Activities\RecordAgentReachOutActivity;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Agents\Actions\Outreach\ReachOutToNewLeadAction;
use Kanvas\Intelligence\Agents\Activities\ReachOutToNewLeadActivity;
use Kanvas\Intelligence\Agents\Activities\RespondOnAiAssistChannelActivity;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Knowledge\Activities\IndexAgentDocumentsActivity;
use Kanvas\Intelligence\Knowledge\Activities\PruneDeletedFileKnowledgeActivity;
use Kanvas\Intelligence\Leads\Enums\AgentReachOutConfigEnum;
use Kanvas\Intelligence\Triggers\Activities\ApplyLeadAiModeActivity;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\KanvasActivity;
use Kanvas\Workflow\Models\StoredWorkflow;
use Kanvas\Workflow\Support\TransientEntity;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;
use Tests\Workflow\Support\WatchesWorkflowEvents;

/**
 * What an agent does on its own runs as an app default rule, and the rule is only a trigger: the
 * activity reads the switch (the agent's own row, the knowledge base) and records a run that had
 * nothing to do as skipped.
 */
final class AgentAppDefaultActivitiesTest extends TestCase
{
    use DatabaseTransactions;
    use WatchesWorkflowEvents;

    protected $connectionsToTransact = [null, 'crm', 'social', 'intelligence', 'workflow'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = auth()->user()->getCurrentCompany();

        // Agents other tests left on the shared company must not answer for this one.
        Agent::query()
            ->servingCompany($this->kanvasApp, $this->company)
            ->get()
            ->filter(fn (Agent $agent): bool => $agent->reachesOutToNewLeads())
            ->each(fn (Agent $agent) => $agent->switchReachOut(false));
    }

    public function testANewLeadIsReachedOnlyWhenAnAgentHasReachOutSwitchedOn(): void
    {
        $lead = $this->lead(['internet_first_fu_active_default' => false]);
        $this->agent();

        [$activity, $result] = $this->runActivity(ReachOutToNewLeadActivity::class, $lead);
        $this->assertSkipped($activity, $result);
        $this->assertSame(1, $activity->tries, 'a retry after a partial send would message the customer twice');

        $reachOut = $this->agent();
        $reachOut->switchReachOut();

        [$activity, $result] = $this->runActivity(ReachOutToNewLeadActivity::class, $lead);

        $this->assertNull($activity->workflowStatus(), 'the reach-out ran');
        $this->assertSame('first_message_disabled', $lead->get(AgentReachOutConfigEnum::REASON->value));
        $this->assertTrue(Agent::forNewLeads($this->kanvasApp, $this->company)->is($reachOut));
    }

    public function testATemplateAgentWithNoCompanyNeverReachesOutForOne(): void
    {
        $this->agent(companyId: 0)->switchReachOut();

        $this->assertNull(Agent::forNewLeads($this->kanvasApp, $this->company));

        $own = $this->agent();
        $own->switchReachOut();

        $this->assertTrue(Agent::forNewLeads($this->kanvasApp, $this->company)->is($own));
    }

    public function testAPausedAgentDoesNotReachOut(): void
    {
        $agent = $this->agent();
        $agent->switchReachOut();
        $agent->forceFill(['is_active' => false])->saveOrFail();

        $this->assertNull(Agent::forNewLeads($this->kanvasApp, $this->company));
    }

    /**
     * Both rules decide when they run, so a switch turned off in between still leaves the lead its
     * mode: the reach-out finds nobody, and the mode rule then applies it.
     */
    public function testASwitchTurnedOffWhileTheReachOutWaitsReachesNobodyAndStillGivesTheLeadItsAiMode(): void
    {
        $reachedOut = $this->watchWorkflowEvent(WorkflowEnum::AGENT_REACHED_OUT->value, Lead::class);
        $lead = $this->lead();
        $this->agent();

        [$activity, $result] = $this->runActivity(ReachOutToNewLeadActivity::class, $lead);

        $this->assertSkipped($activity, $result);
        $this->assertNull($lead->get(AgentReachOutConfigEnum::STATUS->value));
        $this->assertCount(0, $this->firedWith($reachedOut));

        [$activity, $result] = $this->applyMode($lead, TriggersEnum::NEW_LEAD, reachOutAppliesMode: true);

        $this->assertNull($activity->workflowStatus());
        $this->assertArrayHasKey('mods_current', $result);
    }

    public function testAnAgentPausedWhileTheReachOutWaitsReachesNobody(): void
    {
        $reachedOut = $this->watchWorkflowEvent(WorkflowEnum::AGENT_REACHED_OUT->value, Lead::class);
        $lead = $this->lead();
        $agent = $this->agent();
        $agent->switchReachOut();
        $agent->forceFill(['is_active' => false])->saveOrFail();

        [$activity, $result] = $this->runActivity(ReachOutToNewLeadActivity::class, $lead);

        $this->assertSkipped($activity, $result);
        $this->assertNull($lead->get(AgentReachOutConfigEnum::STATUS->value));
        $this->assertCount(0, $this->firedWith($reachedOut));
    }

    public function testANewLeadAnAgentReachesOutToGetsItsAiModeFromThatReachOutOnly(): void
    {
        $lead = $this->lead();
        $this->agent()->switchReachOut();

        [$activity, $result] = $this->applyMode($lead, TriggersEnum::NEW_LEAD, reachOutAppliesMode: true);

        $this->assertSkipped($activity, $result);
    }

    public function testANewLeadNobodyReachesOutToGetsItsAiModeFromTheRule(): void
    {
        $lead = $this->lead();
        $this->agent();

        [$activity, $result] = $this->applyMode($lead, TriggersEnum::NEW_LEAD, reachOutAppliesMode: true);

        $this->assertNull($activity->workflowStatus());
        $this->assertArrayHasKey('mods_current', $result);
        $this->assertSame(3, $activity->tries);
    }

    public function testAnImportThatAnnouncesTheTriggerItselfStillGetsTheAiMode(): void
    {
        $lead = $this->lead();
        $this->agent()->switchReachOut();

        [$activity, $result] = $this->applyMode($lead, TriggersEnum::NEW_LEAD);

        $this->assertNull($activity->workflowStatus());
        $this->assertArrayHasKey('mods_current', $result);
    }

    public function testALeadTypeThatSwitchedTheFirstMessageOffIsSkipped(): void
    {
        $reachedOut = $this->watchWorkflowEvent(WorkflowEnum::AGENT_REACHED_OUT->value, Lead::class);
        $lead = $this->lead(['internet_first_fu_active_default' => false]);
        $agent = $this->agent();
        $agent->switchReachOut();

        $result = new ReachOutToNewLeadAction($lead, $agent)->execute();

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('first_message_disabled', $lead->get(AgentReachOutConfigEnum::REASON->value));
        $this->assertCount(0, $this->firedWith($reachedOut));
    }

    public function testTheAgentsOwnLeadSourcesLimitTheReachOutAndConnectorsHearNothingOfASkip(): void
    {
        $reachedOut = $this->watchWorkflowEvent(WorkflowEnum::AGENT_REACHED_OUT->value, Lead::class);
        $lead = $this->lead();
        $agent = $this->agent();
        $agent->switchReachOut(leadSources: ['a-source-no-lead-has']);

        new ReachOutToNewLeadAction($lead, $agent)->execute();

        $this->assertStringStartsWith(
            'source_not_allowed',
            (string) $lead->get(AgentReachOutConfigEnum::REASON->value)
        );
        $this->assertCount(0, $this->firedWith($reachedOut));
    }

    public function testConnectorsHearOfAReachOutThatSentSomething(): void
    {
        Notification::fake();
        Http::fake();
        $reachedOut = $this->watchWorkflowEvent(WorkflowEnum::AGENT_REACHED_OUT->value, Lead::class);
        $this->company->set(ConfigurationEnum::AI_AGENT_USER_ID->value, auth()->user()->getId());
        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );
        $lead = $this->lead();
        $type = AgentType::factory()
            ->withAppId($this->kanvasApp->getId())
            ->create(['provider' => 'neuron', 'handler' => SalesNeuronAgentStub::class]);
        $agent = $this->agent();
        $agent->forceFill(['agent_type_id' => $type->getId()])->saveOrFail();
        $agent->switchReachOut();

        $result = new ReachOutToNewLeadAction($lead, $agent)->execute();

        $this->assertSame($agent->getId(), $result['agent_id'] ?? null);
        $params = $this->firedWith($reachedOut)->sole();
        $this->assertSame($agent->getId(), $params['agent_id']);
        $this->assertSame(
            array_map('intval', [...($result['message_ids_sent'] ?? []), ...($result['message_ids_scheduled'] ?? [])]),
            [...$params['message_ids_sent'], ...$params['message_ids_scheduled']]
        );
    }

    public function testALeadsAiModeIsAppliedWithoutARuleOfTheCompanysOwn(): void
    {
        $aiTrigger = $this->watchWorkflowEvent(WorkflowEnum::TRIGGER_AI->value, Lead::class);
        $lead = $this->lead();
        $this->agent();

        $lead->announceAiTrigger(TriggersEnum::MANUAL_OFF->value);

        $this->assertSame(TriggersEnum::MANUAL_OFF->value, $this->firedWith($aiTrigger)->sole()['trigger_type']);

        [$activity, $result] = $this->applyMode($lead, TriggersEnum::MANUAL_OFF);

        $this->assertNull($activity->workflowStatus());
        $this->assertArrayHasKey('mods_current', $result);
    }

    public function testALeadOfACompanyNoAgentServesKeepsItsAiModeAsItIs(): void
    {
        $this->agent();
        $companyNoAgentServes = new Companies();
        $companyNoAgentServes->id = (int) Companies::query()->max('id') + 1_000_000;
        $lead = $this->lead();
        $lead->setRelation('company', $companyNoAgentServes);

        [$activity, $result] = $this->applyMode($lead, TriggersEnum::MANUAL_OFF);

        $this->assertSkipped($activity, $result);
    }

    public function testOnlyAPersonsMessageOnTheAiAssistChannelIsAnswered(): void
    {
        $aiAssist = $this->channel(ChannelNameEnum::AI_ASSIST->value);
        $other = $this->channel('WhatsApp');

        [$activity, $result] = $this->runActivity(RespondOnAiAssistChannelActivity::class, $aiAssist, ['message' => $this->message(fromHuman: false)]);
        $this->assertSkipped($activity, $result);

        [$activity, $result] = $this->runActivity(RespondOnAiAssistChannelActivity::class, $other, ['message' => $this->message(fromHuman: true)]);
        $this->assertSkipped($activity, $result);
        $this->assertSame(3, $activity->tries);
    }

    public function testAChannelWithNoAgentSessionIsNotAnswered(): void
    {
        $channel = $this->channel(ChannelNameEnum::AI_ASSIST->value);
        $channel->set(ConfigurationEnum::AGENT_CHANNEL_TYPE->value, 'AI_ASSIST');

        [$activity, $result] = $this->runActivity(RespondOnAiAssistChannelActivity::class, $channel, ['message' => $this->message(fromHuman: true)]);

        $this->assertSkipped($activity, $result);
        $this->assertSame('No agent session on the channel', $result['message']);
        $this->assertSame(0, $channel->messages()->count());
    }

    public function testAFileAttachedToAnAgentIsIndexedWithoutARuleOfTheCompanysOwn(): void
    {
        config(['scout.typesense.api_key' => 'a-key']);
        $agent = $this->agent();

        [$activity, $result] = $this->runActivity(IndexAgentDocumentsActivity::class, $this->lead());
        $this->assertSkipped($activity, $result);

        [$activity, $result] = $this->runActivity(IndexAgentDocumentsActivity::class, $agent);

        $this->assertNull($activity->workflowStatus());
        $this->assertSame(['documents' => 0, 'chunks' => 0], $result);
        $this->assertSame(3, $activity->tries);
    }

    /**
     * A file deleted outright has no row by the time the queued run starts: it travels as its own
     * attributes, which is all the pruning needs.
     */
    public function testADeletedFileReachesThePruneWithItsUuidAndCompany(): void
    {
        $file = $this->file();
        $uuid = (string) $file->uuid;
        $file->delete();

        $restored = TransientEntity::unwrap(unserialize(serialize(TransientEntity::wrap($file))));

        $this->assertInstanceOf(Filesystem::class, $restored);
        $this->assertSame($uuid, (string) $restored->uuid);
        $this->assertSame($this->company->getId(), (int) $restored->companies_id);
    }

    /**
     * Building the knowledge store throws without credentials, and deleting a file must not fail
     * because an app that never indexed anything has nothing to prune.
     */
    public function testAnAppWithNoKnowledgeBaseNeitherIndexesNorPrunes(): void
    {
        config(['scout.typesense.api_key' => '']);
        // An app row that was never saved has no settings of its own: only the platform key decides.
        $app = new Apps();
        $agent = new Agent();
        $agent->setRelation('app', $app);
        $file = new Filesystem();
        $file->forceFill(['uuid' => 'a-deleted-file', 'companies_id' => 7]);

        [$activity, $result] = $this->runActivity(PruneDeletedFileKnowledgeActivity::class, $file, app: $app);
        $this->assertSkipped($activity, $result);
        $this->assertSame(3, $activity->tries);

        [$activity, $result] = $this->runActivity(IndexAgentDocumentsActivity::class, $agent, app: $app);
        $this->assertSkipped($activity, $result);
    }

    public function testSalesAssistRecordsAReachOutOnlyWhenOneWasSent(): void
    {
        [$activity, $result] = $this->runActivity(RecordAgentReachOutActivity::class, $this->lead(), ['agent_id' => 1]);

        $this->assertSkipped($activity, $result);
    }

    /**
     * @param class-string<KanvasActivity> $activityClass
     *
     * @return array{0: KanvasActivity, 1: array<string, mixed>}
     */
    private function runActivity(
        string $activityClass,
        object $entity,
        array $params = [],
        ?Apps $app = null
    ): array {
        Queue::fake();
        $activity = new $activityClass(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        );

        return [$activity, $activity->execute($entity, $app ?? $this->kanvasApp, $params)];
    }

    /**
     * @return array{0: KanvasActivity, 1: array<string, mixed>}
     */
    private function applyMode(Lead $lead, TriggersEnum $trigger, bool $reachOutAppliesMode = false): array
    {
        return $this->runActivity(
            ApplyLeadAiModeActivity::class,
            $lead,
            [
                'trigger_type' => $trigger->value,
                ...($reachOutAppliesMode ? [Lead::REACH_OUT_APPLIES_MODE => true] : []),
            ]
        );
    }

    private function assertSkipped(KanvasActivity $activity, array $result): void
    {
        $this->assertTrue($result['skipped'] ?? false, 'expected a skip, got ' . json_encode($result));
        $this->assertSame(StatusEnum::SKIPPED, $activity->workflowStatus());
    }

    private function agent(?int $companyId = null): Agent
    {
        return Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($companyId ?? $this->company->getId())
            ->create();
    }

    /**
     * @param array<string, mixed> $leadTypeConfig
     */
    private function lead(array $leadTypeConfig = []): Lead
    {
        $type = LeadType::firstOrCreate(
            [
                'apps_id' => $this->kanvasApp->getId(),
                'companies_id' => $this->company->getId(),
                'name' => 'Internet ' . uniqid(),
            ],
            ['description' => 'Internet Lead', 'is_active' => 1],
        );
        $type->config = $leadTypeConfig;
        $type->saveOrFail();

        return Lead::factory()
            ->withAppAndCompany($this->kanvasApp->getId(), $this->company->getId())
            ->create(['leads_types_id' => $type->getId()]);
    }

    private function channel(string $name): Channel
    {
        return Channel::query()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => auth()->user()->getId(),
            'name' => $name,
            'slug' => 'agent-activities-' . uniqid(),
            'description' => $name,
        ]);
    }

    private function message(bool $fromHuman): Message
    {
        return Message::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['message' => ['content' => 'Where does this lead stand?', 'from_human' => $fromHuman]]);
    }

    private function file(): Filesystem
    {
        $file = new Filesystem();
        $file->apps_id = $this->kanvasApp->getId();
        $file->companies_id = $this->company->getId();
        $file->users_id = auth()->user()->getId();
        $file->name = 'price-sheet.txt';
        $file->path = 'files/knowledge/' . uniqid() . '-price-sheet.txt';
        $file->url = 'https://cdn.example.com/files/knowledge/' . uniqid() . '-price-sheet.txt';
        $file->file_type = 'txt';
        $file->size = '1024';
        $file->is_deleted = 0;
        $file->saveOrFail();

        return $file;
    }
}
