<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\KanvasMessageHistory;
use Kanvas\Intelligence\Agents\Services\NativeChannelDeliveryService;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as SessionDto;
use Kanvas\NervousSystem\Scheduling\Actions\CreateScheduledActionAction;
use Kanvas\NervousSystem\Scheduling\DataTransferObject\ScheduledAction as ScheduledActionData;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionTypeEnum;
use Kanvas\NervousSystem\Scheduling\Jobs\RunScheduledAgentActionJob;
use Kanvas\NervousSystem\Scheduling\Notifications\ScheduledReminderNotification;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Users\Models\Users;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use ReflectionMethod;
use Tests\TestCase;

class ScheduledActionChannelDeliveryTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'crm'];

    /**
     * @return array{0: Apps, 1: Companies, 2: Users}
     */
    private function context(): array
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        return [$app, $user->getCurrentCompany(), $user];
    }

    private function makeAgent(Apps $app, Companies $company, Users $user): Agent
    {
        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);
    }

    /**
     * An internal-style channel (no `agent_channel_type`, non-lead entity, neutral slug) so native push
     * is a no-op and only the deterministic feed persist runs — the part we assert on.
     */
    private function makeChannel(Apps $app, Companies $company, Users $user): Channel
    {
        $people = People::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
        ]);

        return new CreateChannelAction(
            new ChannelDto(
                apps: $app,
                companies: $company,
                users: $user,
                entity_id: (string) $people->getId(),
                entity_namespace: People::class,
                name: 'Scheduled delivery test',
                slug: 'sched-internal-' . uniqid(),
            ),
        )->execute();
    }

    private function makeSession(Apps $app, Companies $company, Users $user, Agent $agent, Channel $channel): string
    {
        $session = new CreateSessionAction(
            SessionDto::from([
                'app' => $app,
                'company' => $company,
                'channel' => $channel,
                'entity_namespace' => People::class,
                'entity_id' => (int) $channel->entity_id,
                'user' => ['id' => $user->getId(), 'name' => $user->firstname, 'email' => $user->email],
                'agent' => $agent,
            ]),
        )->execute();

        return $session->uuid;
    }

    public function testReminderWithANonNativeChannelPostsToFeedAndFallsBackToNotification(): void
    {
        Notification::fake();
        [$app, $company, $user] = $this->context();

        $agent = $this->makeAgent($app, $company, $user);
        // A non-lead, non-Slack/WhatsApp channel: we can't push natively, so the message is mirrored to
        // the feed AND the general notification still reaches the user — nobody is left un-pinged.
        $channel = $this->makeChannel($app, $company, $user);
        $sessionUuid = $this->makeSession($app, $company, $user, $agent, $channel);

        $action = new CreateScheduledActionAction(
            new ScheduledActionData(
                app: $app,
                company: $company,
                user: $user,
                type: ScheduledActionTypeEnum::REMINDER,
                timezone: 'UTC',
                runAt: Carbon::now()->addHour(),
                agent: $agent,
                message: 'Time to call the client back',
                sessionUuid: $sessionUuid,
            ),
        )->execute();
        $action->run_at = Carbon::now()->subMinute();
        $action->saveOrFail();

        new RunScheduledAgentActionJob($app, $company, $action)->handle();

        // The reminder landed in the conversation feed (Kanvas visibility)...
        $posted = $channel->messages()
            ->where('messages.message', 'like', '%Time to call the client back%')
            ->first();
        $this->assertNotNull($posted, 'The reminder should be posted into the channel feed.');

        // ...and since no native push was possible, the general notification still fired.
        Notification::assertSentTo($user, ScheduledReminderNotification::class);
    }

    public function testReminderInAnSmsChannelRoutesToSmsAndFallsBackWhenUndeliverable(): void
    {
        Notification::fake();
        [$app, $company, $user] = $this->context();

        $agent = $this->makeAgent($app, $company, $user);
        $channel = $this->makeChannel($app, $company, $user);
        // Mark it an SMS channel so native push routes to the Twilio branch. Without a Twilio from-number
        // configured the native send is a no-op, so the general notification must still reach the user.
        $channel->set(ConfigurationEnum::AGENT_CHANNEL_TYPE->value, 'SMS');
        $sessionUuid = $this->makeSession($app, $company, $user, $agent, $channel);

        $action = new CreateScheduledActionAction(
            new ScheduledActionData(
                app: $app,
                company: $company,
                user: $user,
                type: ScheduledActionTypeEnum::REMINDER,
                timezone: 'UTC',
                runAt: Carbon::now()->addHour(),
                agent: $agent,
                message: 'SMS reminder body',
                sessionUuid: $sessionUuid,
            ),
        )->execute();
        $action->run_at = Carbon::now()->subMinute();
        $action->saveOrFail();

        new RunScheduledAgentActionJob($app, $company, $action)->handle();

        $this->assertNotNull(
            $channel->messages()->where('messages.message', 'like', '%SMS reminder body%')->first(),
        );
        Notification::assertSentTo($user, ScheduledReminderNotification::class);
    }

    public function testReminderWithoutAChannelStillNotifies(): void
    {
        Notification::fake();
        [$app, $company, $user] = $this->context();

        $action = new CreateScheduledActionAction(
            new ScheduledActionData(
                app: $app,
                company: $company,
                user: $user,
                type: ScheduledActionTypeEnum::REMINDER,
                timezone: 'UTC',
                runAt: Carbon::now()->addHour(),
                agent: $this->makeAgent($app, $company, $user),
                message: 'No channel here',
            ),
        )->execute();
        $action->run_at = Carbon::now()->subMinute();
        $action->saveOrFail();

        new RunScheduledAgentActionJob($app, $company, $action)->handle();

        Notification::assertSentTo($user, ScheduledReminderNotification::class);
    }

    public function testInjectedWakeTurnIsPersistedAsNonPublicAndTheReplyStaysVisible(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);
        $sessionUuid = Str::uuid()->toString();

        // privateUserTurn: true is how the kernel configures the handler for an injected wake — the user
        // turn lands as is_public=0 (the frontend hides it), the agent's reply stays visible.
        $history = new KanvasMessageHistory(
            app: $app,
            company: $company,
            user: $user,
            agentClass: 'Stub\\Agent',
            sessionId: $sessionUuid,
            agent: $agent,
            privateUserTurn: true,
        );

        $history->addMessage(new UserMessage('Check the support queue and follow up with anyone waiting'));
        $history->addMessage(new AssistantMessage('Followed up with 2 leads.'));

        $conversationId = $history->getConversationId();

        $wakeRow = DB::connection('intelligence')->table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)->where('role', 'user')->first();
        $this->assertNotNull($wakeRow);
        $this->assertSame(0, (int) $wakeRow->is_public, 'The injected wake turn must be is_public=0 so the UI hides it.');

        $replyRow = DB::connection('intelligence')->table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)->where('role', 'assistant')->first();
        $this->assertNotNull($replyRow);
        $this->assertSame(1, (int) $replyRow->is_public, 'A normal turn stays visible.');
    }

    public function testSlackTargetIsParsedFromSessionCanalIdInExactCase(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);
        $channel = $this->makeChannel($app, $company, $user);

        // The session's canal_id carries the connector destination in ORIGINAL case; the channel slug is
        // lowercased and would 404 against the Slack API. `slack:{team}:{channel}:{thread_ts}`.
        // The parsing moved to NativeChannelDeliveryService when the plan-outcome path needed the same
        // push; the delivery action delegates to it now.
        [$slackChannelId, $threadTs] = new ReflectionMethod(
            NativeChannelDeliveryService::class,
            'slackTargetFromCanalId',
        )->invoke(null, 'slack:T0BC3HTQYAC:D0BKWG1JJ2X:1699999999.001');

        $this->assertSame('D0BKWG1JJ2X', $slackChannelId);
        $this->assertSame('1699999999.001', $threadTs);
    }
}
