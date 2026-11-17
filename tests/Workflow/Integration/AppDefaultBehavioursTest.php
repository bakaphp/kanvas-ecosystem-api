<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Messages\Activities\RespondToInboundMessageActivity;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Leads\Activities\DeliverHumanReplyActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Activities\ReachOutToNewLeadActivity;
use Kanvas\Intelligence\Agents\Activities\RespondOnAiAssistChannelActivity;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Knowledge\Activities\IndexAgentDocumentsActivity;
use Kanvas\Intelligence\Knowledge\Activities\PruneDeletedFileKnowledgeActivity;
use Kanvas\Intelligence\Triggers\Activities\ApplyLeadAiModeActivity;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Actions\EnsureAppDefaultRulesAction;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Tests\TestCase;
use Tests\Workflow\Support\WatchesWorkflowEvents;

/**
 * The behaviours `workflows.app_default_rules` ships: one rule per app for each, on the event and
 * model the activity expects, and the conditions that keep an event that concerns nobody from
 * starting a run at all.
 */
final class AppDefaultBehavioursTest extends TestCase
{
    use DatabaseTransactions;
    use WatchesWorkflowEvents;

    protected $connectionsToTransact = [null, 'workflow', 'social'];

    /**
     * activity => [trigger, model]
     */
    private const array BEHAVIOURS = [
        RespondToInboundMessageActivity::class => [WorkflowEnum::AFTER_ADDING_MESSAGE_TO_CHANNEL, Channel::class],
        DeliverHumanReplyActivity::class => [WorkflowEnum::UPDATED, Channel::class],
        RespondOnAiAssistChannelActivity::class => [WorkflowEnum::UPDATED, Channel::class],
        ReachOutToNewLeadActivity::class => [WorkflowEnum::CREATED, Lead::class],
        ApplyLeadAiModeActivity::class => [WorkflowEnum::TRIGGER_AI, Lead::class],
        IndexAgentDocumentsActivity::class => [WorkflowEnum::ATTACH_FILE, Agent::class],
        PruneDeletedFileKnowledgeActivity::class => [WorkflowEnum::DELETED, Filesystem::class],
    ];

    /** @var array<class-string, Rule> */
    private array $rules = [];

    protected function setUp(): void
    {
        parent::setUp();

        // CI seeds the catalog with kanvas:workflow-sync-actions; a fresh database may not have it.
        foreach (array_keys(self::BEHAVIOURS) as $activity) {
            Action::query()->firstOrCreate(['model_name' => $activity], ['name' => class_basename($activity)]);
        }

        // The file, not config(): a cached configuration would hand the test whatever list it was cached with.
        config(['workflows.app_default_rules' => (require base_path('config/workflows.php'))['app_default_rules']]);

        foreach (new EnsureAppDefaultRulesAction(app(Apps::class))->execute() as $rule) {
            $activity = $rule->workflowActivities->first()?->activity?->actionClass();
            $this->rules[$activity] = $rule;
        }
    }

    public function testEveryBehaviourIsOneAsyncRuleForEveryCompanyOfTheApp(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(self::BEHAVIOURS), array_keys($this->rules));

        foreach (self::BEHAVIOURS as $activity => [$trigger, $model]) {
            $rule = $this->rules[$activity];

            $this->assertTrue($rule->isAppDefault(), $activity);
            $this->assertTrue($rule->is_async, $activity);
            $this->assertSame($trigger->value, $rule->type->name, $activity);
            $this->assertSame($model, $rule->systemModule->model_name, $activity);
        }
    }

    public function testAPersonsReplyIsDeliveredAndNoOtherMessageStartsARun(): void
    {
        $rule = $this->rules[DeliverHumanReplyActivity::class];
        $channel = $this->channel('Lead conversation');

        $this->assertTrue($rule->matches($channel, $this->params($channel, fromHuman: true)));
        $this->assertFalse($rule->matches($channel, $this->params($channel, fromHuman: false)), 'an agent\'s or a customer\'s message');
        $this->assertFalse($rule->matches($channel, ['message' => null]), 'a channel update that carries no message');
    }

    public function testOnlyAPersonsMessageOnTheAiAssistChannelStartsARun(): void
    {
        $rule = $this->rules[RespondOnAiAssistChannelActivity::class];
        $aiAssist = $this->channel(ChannelNameEnum::AI_ASSIST->value);
        $other = $this->channel('WhatsApp');

        $this->assertTrue($rule->matches($aiAssist, $this->params($aiAssist, fromHuman: true)));
        $this->assertFalse($rule->matches($aiAssist, $this->params($aiAssist, fromHuman: false)), 'the agent\'s own answer');
        $this->assertFalse($rule->matches($other, $this->params($other, fromHuman: true)), 'a person on another channel');
    }

    /**
     * The flag the conditions read is the one adding a message to a channel actually sends.
     */
    public function testAddingAMessageToAChannelSaysWhetherAPersonWroteIt(): void
    {
        $updated = $this->watchWorkflowEvent(
            WorkflowEnum::UPDATED->value,
            Channel::class,
            [['from_human', '==', 'true']]
        );
        $channel = $this->channel('Lead conversation');

        $channel->addMessage($this->message(fromHuman: false));
        $this->assertCount(0, $this->firedWith($updated));

        $human = $this->message(fromHuman: true);
        $channel->addMessage($human);

        $run = $this->firedWith($updated)->sole();
        $this->assertTrue($run['from_human']);
        $this->assertTrue($run['message']->is($human));
    }

    /**
     * @return array<string, mixed> what Channel::addMessage() fires `updated` with
     */
    private function params(Channel $channel, bool $fromHuman): array
    {
        $message = $this->message($fromHuman);

        return [
            'message' => $message,
            'user' => null,
            'app' => $channel->app,
            'company' => $channel->company,
            'from_human' => $message->isFromHuman(),
            'currentEventTypeName' => WorkflowEnum::UPDATED->value,
        ];
    }

    private function channel(string $name): Channel
    {
        return Channel::query()->create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'users_id' => auth()->user()->getId(),
            'name' => $name,
            'slug' => 'app-default-behaviours-' . uniqid(),
            'description' => $name,
        ]);
    }

    private function message(bool $fromHuman): Message
    {
        return Message::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create(['message' => ['content' => 'Any update?', 'from_human' => $fromHuman]]);
    }
}
