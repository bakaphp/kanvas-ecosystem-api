<?php

declare(strict_types=1);

namespace Tests\Communication\Setup;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Enums\ConfigurationEnum as MetaConfigurationEnum;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Handlers\MetaWhatsAppHandler;
use Kanvas\Communication\Connections\Enums\SetupStatusEnum;
use Kanvas\Communication\Connections\Exceptions\SetupBlockedException;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Messages\Enums\ConversationAgentEnum;
use Kanvas\Communication\Setup\Actions\SetupCompanyDefaultsAction;
use Kanvas\Communication\Setup\Actions\SetupCompanyRulesAction;
use Kanvas\Communication\Setup\Actions\SetupCompanySettingsAction;
use Kanvas\Communication\Setup\Enums\DefaultAgentEnum;
use Kanvas\Communication\Setup\Enums\DefaultRuleEnum;
use Kanvas\Communication\Setup\Enums\SetupSettingEnum;
use Kanvas\Communication\Setup\Jobs\SetupPeopleChannelsJob;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Activities\SalesAssistAgentReachOutActivity;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpTypeEnum;
use Kanvas\Intelligence\FollowUp\Models\FollowUp;
use Kanvas\Intelligence\FollowUp\Models\FollowUpDay;
use Kanvas\Intelligence\FollowUp\Models\FollowUpTemplate;
use Kanvas\Intelligence\Sessions\Models\Session as SessionModel;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Intelligence\Triggers\Workflows\TriggerIntelligenceActivity;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Workflows\Activities\PrivatizeConversationMessageActivity;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Rules\Actions\CreateRuleAction;
use Kanvas\Workflow\Rules\DataTransferObject\Rule as RuleData;
use Kanvas\Workflow\Rules\DataTransferObject\RuleActionData;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Services\WorkflowActionCatalog;
use PHPUnit\Framework\Attributes\Group;
use Spatie\LaravelData\DataCollection;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\TestCase;

/**
 * What completing a messaging integration leaves a company with besides its connections: its agents,
 * its lead types and their config, every person's channels, and the switches that put it to work.
 */
// Writes app settings, which every paratest process shares through Redis.
#[Group('serial')]
final class SetupCompanyDefaultsTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social', 'intelligence'];

    private const array INTERNET_CONFIG = [
        'internet_ai_mode_open_default' => 'full_on',
        'internet_con_fu_active_default' => 'on',
        'internet_first_fu_active_default' => true,
    ];

    private const array OTHER_CONFIG = ['con_fu_active_default' => 'on'];

    /**
     * @var array<string, mixed>
     */
    private array $appSettings = [];

    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([...array_column(SetupSettingEnum::cases(), 'value'), IntelligenceConfigurationEnum::USE_LEAD_FOLLOW_UP_V2->value] as $setting) {
            $this->appSettings[$setting] = $this->currentApp()->get($setting);
        }
        $this->currentApp()->del(SetupSettingEnum::FOLLOW_UP_PIPELINE->value);

        $this->company = $this->otherCompany();
    }

    protected function tearDown(): void
    {
        foreach ($this->appSettings as $key => $value) {
            $value === null ? $this->currentApp()->del($key) : $this->currentApp()->set($key, $value);
        }

        parent::tearDown();
    }

    public function testACompanyGetsACopyOfEachGlobalAgent(): void
    {
        $templates = $this->globalAgentTemplates();
        $templates[DefaultAgentEnum::FOLLOW_UP_ENGAGER->value]->forceFill(['is_sub_agent' => true])->saveOrFail();

        $created = $this->setupDefaults();

        $this->assertEqualsCanonicalizing(
            array_column(DefaultAgentEnum::cases(), 'value'),
            $created['agents']
        );

        foreach (DefaultAgentEnum::cases() as $default) {
            $agent = $this->companyAgent($default);

            $this->assertNotNull($agent, $default->value . ' was not copied');
            $this->assertNotSame($templates[$default->value]->getId(), $agent->getId());
            $this->assertSame($templates[$default->value]->agent_type_id, $agent->agent_type_id);
        }

        $this->assertTrue((bool) $this->companyAgent(DefaultAgentEnum::FOLLOW_UP_ENGAGER)->is_sub_agent);
    }

    public function testAnAgentTheCompanyHasIsKept(): void
    {
        $this->globalAgentTemplates();
        $own = Agent::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->company->getId())
            ->create(['name' => DefaultAgentEnum::SALLY->value]);

        $created = $this->setupDefaults();

        $this->assertNotContains(DefaultAgentEnum::SALLY->value, $created['agents']);
        $this->assertSame($own->getId(), $this->companyAgent(DefaultAgentEnum::SALLY)->getId());
        $this->assertSame([], $this->setupDefaults()['agents'], 'a second pass copies nothing');
    }

    public function testAMissingGlobalAgentBlocksSetupBeforeAnythingIsCopied(): void
    {
        $templates = $this->globalAgentTemplates();
        $templates[DefaultAgentEnum::POLLY->value]->forceFill(['is_deleted' => 1])->saveOrFail();

        try {
            $this->setupDefaults();
            $this->fail('setup ran without a template for Polly');
        } catch (SetupBlockedException $blocked) {
            $this->assertSame('missing_agent_template', $blocked->reason);
            $this->assertStringContainsString(DefaultAgentEnum::POLLY->value, $blocked->getMessage());
        }

        $this->assertNull($this->companyAgent(DefaultAgentEnum::SALLY));
    }

    public function testLeadTypesWithoutConfigGetTheAppDefaults(): void
    {
        $this->globalAgentTemplates();
        $this->currentApp()->set(SetupSettingEnum::INTERNET_LEAD_TYPE_CONFIG->value, self::INTERNET_CONFIG);
        $this->currentApp()->set(SetupSettingEnum::OTHER_LEAD_TYPE_CONFIG->value, self::OTHER_CONFIG);
        $internet = $this->leadType('internet');
        $configured = $this->leadType('Showroom', ['showroom_con_fu_active_default' => 'off']);

        $this->setupDefaults();

        $this->assertSame(self::INTERNET_CONFIG, $internet->fresh()->config);
        $this->assertSame(['showroom_con_fu_active_default' => 'off'], $configured->fresh()->config);
        $cold = LeadType::query()->fromCompany($this->company)->where('name', 'Cold')->firstOrFail();
        $this->assertSame(self::OTHER_CONFIG, $cold->config, 'the default lead types are created and configured');
    }

    public function testWithoutTheAppSettingsLeadTypesKeepNoConfig(): void
    {
        $this->globalAgentTemplates();
        $this->currentApp()->del(SetupSettingEnum::INTERNET_LEAD_TYPE_CONFIG->value);
        $this->currentApp()->del(SetupSettingEnum::OTHER_LEAD_TYPE_CONFIG->value);

        $this->assertSame(0, $this->setupDefaults()['lead_types_configured']);
        $this->assertTrue(LeadType::query()->fromCompany($this->company)->exists());
    }

    public function testTheSwitchesTurnTheAgentsOnForNewLeads(): void
    {
        $this->globalAgentTemplates();
        $this->setupDefaults();
        $sally = $this->companyAgent(DefaultAgentEnum::SALLY);

        new SetupCompanySettingsAction($this->currentApp(), $this->company)->execute();

        $this->assertTrue((bool) $this->company->get(IntelligenceConfigurationEnum::AI_ENABLE->value));
        $this->assertTrue((bool) $this->company->get(IntelligenceConfigurationEnum::AI_ASSIST_ENABLED->value));
        $this->assertTrue((bool) $this->company->get(IntelligenceConfigurationEnum::AI_NOTES_CHANNEL_ENABLED->value));
        $this->assertFalse((bool) $this->company->get(IntelligenceConfigurationEnum::LEAD_FOLLOW_UP_DISABLED->value));
        $this->assertNotNull($this->company->get(IntelligenceConfigurationEnum::LEAD_FOLLOW_UP_START_DATE->value));
        $this->assertSame($sally->getId(), (int) $this->company->get(ConversationAgentEnum::SETTING->value));
        $this->assertSame((int) $sally->user_id, (int) $this->company->get(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value));
        $this->assertSame(
            $this->companyAgent(DefaultAgentEnum::FIRST_MESSAGE_ENGAGER)->getId(),
            (int) $this->company->get(CompanyConfigurationEnum::AGENT_REACH_OUT_DEFAULT_AGENT_ID->value)
        );
        $this->assertSame(10, $this->company->get(CompanyConfigurationEnum::MESSAGE_MINUTES_INTERVAL->value));
    }

    public function testEveryCopiedAgentActsAsItsOwnUser(): void
    {
        $this->globalAgentTemplates();
        $this->setupDefaults();

        $users = [];
        foreach (DefaultAgentEnum::cases() as $default) {
            $agent = $this->companyAgent($default);

            $this->assertNotSame((int) $this->company->users_id, (int) $agent->user_id, $default->value . ' runs as the owner');
            $users[] = (int) $agent->user_id;
        }

        $this->assertCount(count(DefaultAgentEnum::cases()), array_unique($users));
    }

    public function testTheReachOutOpensWithTheCompanysOwnFirstMessageAgent(): void
    {
        $this->globalAgentTemplates();
        $firstMessage = Agent::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->company->getId())
            ->create(['name' => DefaultAgentEnum::FIRST_MESSAGE_ENGAGER->value]);
        $this->setupDefaults();

        new SetupCompanySettingsAction($this->currentApp(), $this->company)->execute();

        $this->assertSame($firstMessage->getId(), (int) $this->company->get(CompanyConfigurationEnum::AGENT_REACH_OUT_DEFAULT_AGENT_ID->value));
    }

    public function testTheAiUserIsNeverTheOwner(): void
    {
        Agent::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->company->getId())
            ->create(['name' => DefaultAgentEnum::SALLY->value, 'user_id' => $this->company->users_id]);

        $written = new SetupCompanySettingsAction($this->currentApp(), $this->company)->execute();

        $this->assertNotContains(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $written);
        $this->assertContains(ConversationAgentEnum::SETTING->value, $written);
    }

    public function testASwitchTheCompanyAlreadySetIsLeftAlone(): void
    {
        $this->company->set(IntelligenceConfigurationEnum::AI_ENABLE->value, false);
        $this->company->set(IntelligenceConfigurationEnum::LEAD_FOLLOW_UP_START_DATE->value, '2026-01-01 00:00:00');

        $written = new SetupCompanySettingsAction($this->currentApp(), $this->company)->execute();

        $this->assertNotContains(IntelligenceConfigurationEnum::AI_ENABLE->value, $written);
        $this->assertFalse((bool) $this->company->get(IntelligenceConfigurationEnum::AI_ENABLE->value));
        $this->assertSame('2026-01-01 00:00:00', $this->company->get(IntelligenceConfigurationEnum::LEAD_FOLLOW_UP_START_DATE->value));
        $this->assertNotContains(CompanyConfigurationEnum::AGENT_REACH_OUT_DEFAULT_AGENT_ID->value, $written, 'no first-message agent, no reach-out agent');
    }

    public function testTheBackfillGivesEveryPersonTheirConversationThenSwitchesOn(): void
    {
        $this->globalAgentTemplates();
        $this->setupDefaults();
        $this->turnOnWhatsApp();
        $people = $this->personOf($this->company, '+18095550177');

        new SetupPeopleChannelsJob($this->currentApp(), $this->company)->handle();
        new SetupPeopleChannelsJob($this->currentApp(), $this->company)->handle();

        $slug = SessionChannelService::createChannelSlug('whatsapp', '+18095550177');
        $this->assertSame(1, $people->socialChannels()->where('slug', $slug)->count());
        $this->assertSame(1, SessionModel::query()
            ->where('entity_namespace', People::class)
            ->where('entity_id', (string) $people->getId())
            ->where('canal_id', SessionChannelService::createCanalId('whatsapp', '+18095550177'))
            ->count());
        $this->assertTrue((bool) $this->company->get(IntelligenceConfigurationEnum::AI_ENABLE->value));
    }

    public function testSetupCopiesTheAgentsAndStartsTheBackfillOnceWhenTheConnectionFirstGoesLive(): void
    {
        $this->useFakeProvisioner();
        $integration = $this->integrateMessaging(['+18095550100']);

        $this->runSetup($integration);
        $this->runSetup($integration);

        $connection = Connection::query()->where('integrations_company_id', $integration->getId())->firstOrFail();
        $this->assertSame(SetupStatusEnum::READY, $connection->setup_status);
        $this->assertTrue(Agent::query()
            ->fromApp($this->currentApp())
            ->fromCompany($this->currentCompany())
            ->where('name', DefaultAgentEnum::POLLY->value)
            ->notDeleted()
            ->exists());
        Queue::assertPushed(SetupPeopleChannelsJob::class, 1);
    }

    public function testSetupIsBlockedWhileAGlobalAgentIsMissing(): void
    {
        $this->useFakeProvisioner();
        Agent::query()
            ->fromApp($this->currentApp())
            ->where('companies_id', 0)
            ->where('name', DefaultAgentEnum::FOLLOW_UP_ENGAGER->value)
            ->update(['is_deleted' => 1]);
        Agent::query()
            ->fromApp($this->currentApp())
            ->fromCompany($this->currentCompany())
            ->where('name', DefaultAgentEnum::FOLLOW_UP_ENGAGER->value)
            ->update(['is_deleted' => 1]);
        $integration = $this->integrateMessaging(['+18095550100']);

        $this->runSetup($integration);

        $connection = Connection::query()->where('integrations_company_id', $integration->getId())->firstOrFail();
        $this->assertSame(SetupStatusEnum::BLOCKED, $connection->setup_status);
        $this->assertStringStartsWith('missing_agent_template', (string) $connection->last_error);
        Queue::assertNotPushed(SetupPeopleChannelsJob::class);
    }

    public function testTheAppTurnsFollowUpV2OnOnlyWhenUnset(): void
    {
        $this->currentApp()->del(IntelligenceConfigurationEnum::USE_LEAD_FOLLOW_UP_V2->value);

        new SetupCompanySettingsAction($this->currentApp(), $this->company)->execute();
        $this->assertTrue((bool) $this->currentApp()->get(IntelligenceConfigurationEnum::USE_LEAD_FOLLOW_UP_V2->value));

        $this->currentApp()->set(IntelligenceConfigurationEnum::USE_LEAD_FOLLOW_UP_V2->value, false);
        $written = new SetupCompanySettingsAction($this->currentApp(), $this->company)->execute();

        $this->assertNotContains(IntelligenceConfigurationEnum::USE_LEAD_FOLLOW_UP_V2->value, $written);
        $this->assertFalse((bool) $this->currentApp()->get(IntelligenceConfigurationEnum::USE_LEAD_FOLLOW_UP_V2->value));
    }

    public function testTheDefaultRulesAreCreatedOnce(): void
    {
        $this->globalAgentTemplates();
        $this->setupDefaults();

        $first = new SetupCompanyRulesAction($this->currentApp(), $this->company)->execute();
        $second = new SetupCompanyRulesAction($this->currentApp(), $this->company)->execute();

        $this->assertEqualsCanonicalizing(
            array_column(DefaultRuleEnum::cases(), 'value'),
            [...$first['created'], ...array_keys($first['skipped'])]
        );
        $this->assertSame([], $second['created']);
        $this->assertSame('not_configured', $first['skipped'][DefaultRuleEnum::HUMAN_AGENT_RESPONSE->value]);
        $this->assertSame('not_configured', $first['skipped'][DefaultRuleEnum::GOOGLE_CALENDAR_EVENT->value]);

        $reachOut = $this->companyRule(DefaultRuleEnum::AGENT_REACH_OUT);
        $this->assertSame(['trigger_type' => TriggersEnum::NEW_LEAD->value], $reachOut->params);
        $this->assertSame(
            [TriggerIntelligenceActivity::class, SalesAssistAgentReachOutActivity::class],
            $reachOut->workflowActivities()->orderBy('weight')->get()->map(fn ($ruleAction) => $ruleAction->activity->action->model_name)->all()
        );
        $this->assertSame(
            DefaultAgentEnum::POLLY->of($this->currentApp(), $this->company)->getId(),
            $this->companyRule(DefaultRuleEnum::AI_ASSIST_RESPONDER)->params['agent_id']
        );
    }

    public function testTheHumanReplyRuleSendsFromTheCompanysNumber(): void
    {
        $this->company->set(TwilioConfigurationEnum::TWILIO_PHONE_NUMBER->value, '+18095550111');

        $result = new SetupCompanyRulesAction($this->currentApp(), $this->company)->execute();

        $this->assertContains(DefaultRuleEnum::HUMAN_AGENT_RESPONSE->value, $result['created']);
        $rule = $this->companyRule(DefaultRuleEnum::HUMAN_AGENT_RESPONSE);
        $this->assertSame(['from' => '+18095550111'], $rule->params);
        $this->assertSame('1 AND 2 AND 3 AND 4', $rule->pattern);
    }

    public function testARuleTheWholeAppAlreadyRunsIsNotCopied(): void
    {
        $catalog = new WorkflowActionCatalog();
        new CreateRuleAction(new RuleData(
            app: $this->currentApp(),
            company: new Companies()->forceFill(['id' => 0]),
            user: auth()->user(),
            ruleType: RuleType::query()->where('name', WorkflowEnum::CREATED->value)->firstOrFail(),
            systemModule: SystemModulesRepository::getByModelName(Message::class, $this->currentApp()),
            name: 'App-wide privatize',
            actions: RuleActionData::collect([new RuleActionData($catalog->register(PrivatizeConversationMessageActivity::class))], DataCollection::class),
        ))->execute();

        $result = new SetupCompanyRulesAction($this->currentApp(), $this->company)->execute();

        $this->assertSame('already_runs', $result['skipped'][DefaultRuleEnum::PRIVATIZE_MESSAGES->value]);
        $this->assertNull($this->companyRule(DefaultRuleEnum::PRIVATIZE_MESSAGES));
    }

    public function testTheFollowUpPipelineIsCopiedAndBecomesTheDefault(): void
    {
        $this->globalAgentTemplates();
        $this->currentApp()->set(SetupSettingEnum::INTERNET_LEAD_TYPE_CONFIG->value, self::INTERNET_CONFIG);
        $this->currentApp()->set(SetupSettingEnum::OTHER_LEAD_TYPE_CONFIG->value, self::OTHER_CONFIG);
        [$template, $stage, $nextStage] = $this->followUpPipelineTemplate();
        $this->currentApp()->set(SetupSettingEnum::FOLLOW_UP_PIPELINE->value, $template->getId());

        $pipelineId = $this->setupDefaults()['follow_up_pipeline'];

        $pipeline = Pipeline::query()->findOrFail($pipelineId);
        $this->assertSame($this->company->getId(), (int) $pipeline->companies_id);
        $this->assertTrue($pipeline->isDefault());
        $this->assertSame(['Engaged', 'Appointment'], $pipeline->stages->pluck('name')->all());
        $this->assertTrue((bool) $pipeline->stages->first()->config['follow_up']['enabled']);

        $day = FollowUpDay::query()->whereHas('followUp', fn ($query) => $query->where('pipelines_id', $pipelineId))->sole();
        $this->assertSame($pipeline->stages->first()->getId(), (int) $day->pipeline_stages_id);
        $this->assertSame($pipeline->stages->last()->getId(), (int) $day->move_to_stage_id);
        $this->assertNotSame($stage->getId(), (int) $day->pipeline_stages_id);
        $this->assertSame(['Hola {{name}}'], $day->templates->pluck('template')->all());

        $this->assertNull($this->setupDefaults()['follow_up_pipeline'], 'its lead types now carry the follow-up config');
    }

    public function testACompanyWhoseLeadTypesFollowUpKeepsItsPipeline(): void
    {
        $this->globalAgentTemplates();
        $this->currentApp()->set(SetupSettingEnum::OTHER_LEAD_TYPE_CONFIG->value, self::OTHER_CONFIG);
        $this->leadType('Showroom', ['showroom_con_fu_active_default' => 'on']);
        [$template] = $this->followUpPipelineTemplate();
        $this->currentApp()->set(SetupSettingEnum::FOLLOW_UP_PIPELINE->value, $template->getId());

        $this->assertNull($this->setupDefaults()['follow_up_pipeline']);
    }

    public function testWithoutLeadTypeConfigTheFollowUpPipelineIsNotCopied(): void
    {
        $this->globalAgentTemplates();
        $this->currentApp()->del(SetupSettingEnum::INTERNET_LEAD_TYPE_CONFIG->value);
        $this->currentApp()->del(SetupSettingEnum::OTHER_LEAD_TYPE_CONFIG->value);
        [$template] = $this->followUpPipelineTemplate();
        $this->currentApp()->set(SetupSettingEnum::FOLLOW_UP_PIPELINE->value, $template->getId());

        $this->assertNull($this->setupDefaults()['follow_up_pipeline']);
        $this->assertNull($this->setupDefaults()['follow_up_pipeline'], 'nor on the next pass');
    }

    public function testAFollowUpPipelineSettingPointingNowhereBlocksSetup(): void
    {
        $this->globalAgentTemplates();
        $this->currentApp()->set(SetupSettingEnum::FOLLOW_UP_PIPELINE->value, PHP_INT_MAX);

        try {
            $this->setupDefaults();
            $this->fail('setup ran with a missing follow-up pipeline');
        } catch (SetupBlockedException $blocked) {
            $this->assertSame('missing_follow_up_pipeline', $blocked->reason);
        }

        $this->assertNull(DefaultAgentEnum::SALLY->of($this->currentApp(), $this->company), 'nothing is copied');
    }

    /**
     * @return array{0: Pipeline, 1: PipelineStage, 2: PipelineStage}
     */
    private function followUpPipelineTemplate(): array
    {
        $owner = $this->otherCompany();
        $template = Pipeline::query()->create([
            'apps_id' => $this->currentApp()->getId(),
            'companies_id' => $owner->getId(),
            'users_id' => $owner->users_id,
            'system_modules_id' => SystemModulesRepository::getByModelName(Lead::class, $this->currentApp())->getId(),
            'name' => 'Dealer Follow Up',
            'weight' => 0,
            'is_default' => 1,
        ]);
        $stage = PipelineStage::query()->create([
            'pipelines_id' => $template->getId(),
            'name' => 'Engaged',
            'weight' => 1,
            'config' => ['follow_up' => ['enabled' => true]],
        ]);
        $nextStage = PipelineStage::query()->create(['pipelines_id' => $template->getId(), 'name' => 'Appointment', 'weight' => 2]);

        $followUp = FollowUp::query()->create([
            'apps_id' => $this->currentApp()->getId(),
            'companies_id' => $owner->getId(),
            'follow_up_type' => FollowUpTypeEnum::LEAD_FOLLOW_UP->value,
            'pipelines_id' => $template->getId(),
            'name' => 'Dealer Follow Up',
        ]);
        $day = FollowUpDay::query()->create([
            'follow_ups_id' => $followUp->getId(),
            'pipeline_stages_id' => $stage->getId(),
            'move_to_stage_id' => $nextStage->getId(),
            'name' => 'Day 1',
            'time_value' => 1,
            'time_unit' => 'days',
            'weight' => 1,
            'calendar_day' => true,
            'send_message' => true,
        ]);
        FollowUpTemplate::query()->create([
            'follow_up_days_id' => $day->getId(),
            'communication_channel' => 'sms',
            'name' => 'Day 1 SMS',
            'template' => 'Hola {{name}}',
        ]);

        return [$template, $stage, $nextStage];
    }

    private function companyRule(DefaultRuleEnum $default): ?Rule
    {
        return Rule::query()
            ->fromApp($this->currentApp())
            ->where('companies_id', $this->company->getId())
            ->where('name', $default->value)
            ->where('is_deleted', 0)
            ->first();
    }

    /**
     * @return array{agents: list<string>, lead_types_configured: int, follow_up_pipeline: ?int}
     */
    private function setupDefaults(): array
    {
        return new SetupCompanyDefaultsAction($this->currentApp(), $this->company)->execute();
    }

    private function companyAgent(DefaultAgentEnum $default): ?Agent
    {
        return Agent::query()
            ->fromApp($this->currentApp())
            ->fromCompany($this->company)
            ->where('name', $default->value)
            ->notDeleted()
            ->first();
    }

    private function leadType(string $name, ?array $config = null): LeadType
    {
        return LeadType::query()->create([
            'apps_id' => $this->currentApp()->getId(),
            'companies_id' => $this->company->getId(),
            'name' => $name,
            'description' => $name,
            'config' => $config,
        ]);
    }

    private function personOf(Companies $company, string $number): People
    {
        $people = People::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($company->getId())
            ->create();

        $people->contacts()->create([
            'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
            'value' => $number,
            'weight' => 0,
        ]);

        return $people->fresh();
    }

    private function turnOnWhatsApp(): void
    {
        $integration = Integrations::firstOrCreate(
            ['name' => IntegrationsEnum::META_WHATSAPP->value],
            ['apps_id' => 0, 'config' => [], 'handler' => MetaWhatsAppHandler::class]
        );
        $this->company->set(MetaConfigurationEnum::PHONE_NUMBER_ID->value, '106540352242922');

        IntegrationsCompany::create([
            'apps_id' => $this->currentApp()->getId(),
            'companies_id' => $this->company->getId(),
            'integrations_id' => $integration->getId(),
            'region_id' => 0,
            'status_id' => Status::where('slug', StatusEnum::ACTIVE->value)->where('apps_id', 0)->firstOrFail()->getId(),
        ]);
    }
}
