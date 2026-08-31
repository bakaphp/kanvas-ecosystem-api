<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput as RegisterPostDataDto;
use Kanvas\Intelligence\Agents\Jobs\RespondToMentionJob;
use Kanvas\Intelligence\Agents\Listeners\RespondToAgentMentionListener;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Services\AgentConversationBudget;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Events\MessageMentionsStoredEvent;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Users\Models\Users;
use Tests\Stubs\Intelligence\SystemUserAgentStub;
use Tests\TestCase;

/**
 * One agent handing work to the next.
 *
 * A blanket "agent-authored mentions never wake another agent" guard took the working half with it.
 * On plan 25148 the Format Specialist finished step 1, posted the summary and wrote
 * "@agenthtmltemplatedesigner you may now proceed with Task #11363" — and task #11363 stayed `pending`,
 * because the reply carried `from_ia` and the mention was discarded before delivery. Whether a handoff
 * worked came down to which code path posted it: a board comment stamps `from_agent` and got through,
 * a mention reply stamps `from_ia` and did not.
 *
 * `AgentConversationBudget` is the guard now — bounded hops rather than a blanket refusal.
 */
final class AgentToAgentHandoffMentionTest extends TestCase
{
    public function testAnAgentsMentionReplyHandsOffToTheNextAgent(): void
    {
        Bus::fake([RespondToMentionJob::class]);

        $next = $this->agentUser('agenthtmltemplatedesigner');
        $worker = $this->agentUser('agentformatspecialist');

        $this->route(
            $this->message($worker, '@agenthtmltemplatedesigner you may now proceed.', fromIa: true),
            $next,
        );

        Bus::assertDispatched(
            RespondToMentionJob::class,
            fn (RespondToMentionJob $job): bool => $job->agent->user?->getId() === $next->getId(),
        );
    }

    /** The half that already worked — a board comment stamps `from_agent`, and must keep working. */
    public function testABoardCommentFromAnAgentStillHandsOff(): void
    {
        Bus::fake([RespondToMentionJob::class]);

        $next = $this->agentUser('agenthtmltemplatedesigner');
        $worker = $this->agentUser('agentformatspecialist');

        $this->route(
            $this->message($worker, '@agenthtmltemplatedesigner over to you.', fromAgent: true),
            $next,
        );

        Bus::assertDispatched(RespondToMentionJob::class);
    }

    /**
     * Two agents answering each other has no natural end — a person stops replying, agents do not.
     * The budget is what the blanket guard used to be, only without silencing the first handoff.
     */
    public function testTheExchangeStopsAfterTheHopBudgetIsSpent(): void
    {
        Bus::fake([RespondToMentionJob::class]);

        $next = $this->agentUser('agenthtmltemplatedesigner');
        $worker = $this->agentUser('agentformatspecialist');
        $channel = $this->channel($worker);

        for ($hop = 0; $hop < AgentConversationBudget::MAX_HOPS; $hop++) {
            $this->route($this->message($worker, "@agenthtmltemplatedesigner hop {$hop}", fromIa: true, channel: $channel), $next);
        }

        Bus::assertDispatchedTimes(RespondToMentionJob::class, AgentConversationBudget::MAX_HOPS);

        $this->route(
            $this->message($worker, '@agenthtmltemplatedesigner one more', fromIa: true, channel: $channel),
            $next,
        );

        Bus::assertDispatchedTimes(RespondToMentionJob::class, AgentConversationBudget::MAX_HOPS);
    }

    /** A person re-entering is the signal the exchange is wanted, so it hands the pair their budget back. */
    public function testAHumanSpeakingResetsTheBudget(): void
    {
        Bus::fake([RespondToMentionJob::class]);

        $next = $this->agentUser('agenthtmltemplatedesigner');
        $worker = $this->agentUser('agentformatspecialist');
        $channel = $this->channel($worker);

        for ($hop = 0; $hop < AgentConversationBudget::MAX_HOPS; $hop++) {
            $this->route($this->message($worker, "@agenthtmltemplatedesigner hop {$hop}", fromIa: true, channel: $channel), $next);
        }

        // The human's own mention is never metered, and clears the counter behind it.
        $this->route($this->message($this->human(), '@agenthtmltemplatedesigner carry on', channel: $channel), $next);
        $this->route($this->message($worker, '@agenthtmltemplatedesigner resuming', fromIa: true, channel: $channel), $next);

        Bus::assertDispatchedTimes(RespondToMentionJob::class, AgentConversationBudget::MAX_HOPS + 2);
    }

    private function route(Message $message, Users $mentioned): void
    {
        new RespondToAgentMentionListener()->handle(
            new MessageMentionsStoredEvent($message, [$mentioned->getId()]),
        );
    }

    /**
     * A user with an agent behind it, named by a handle the mention parser can actually match.
     */
    private function agentUser(string $handle): Users
    {
        $dto = RegisterPostDataDto::from([
            'email' => $handle . '-' . uniqid('', true) . '@example.test',
            'password' => 'Password123!',
            'firstname' => fake()->firstName,
            'lastname' => fake()->lastName,
        ]);

        $user = new RegisterUsersAction($dto)->execute();
        $user->displayname = $handle;
        $user->saveQuietly();

        $app = app(Apps::class);
        $company = $this->human()->getCurrentCompany();

        $type = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['provider' => 'neuron', 'handler' => SystemUserAgentStub::class]);

        Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $type->getId(), 'user_id' => $user->getId()]);

        return $user;
    }

    private function channel(Users $owner): Channel
    {
        return new CreateChannelAction(
            new ChannelDto(
                apps: app(Apps::class),
                companies: $this->human()->getCurrentCompany(),
                users: $owner,
                entity_id: (int) $owner->getKey(),
                entity_namespace: $owner::class,
                name: 'Handoff',
                slug: 'handoff-' . uniqid(),
            ),
        )->execute();
    }

    private function message(
        Users $author,
        string $content,
        bool $fromIa = false,
        bool $fromAgent = false,
        ?Channel $channel = null,
    ): Message {
        $app = app(Apps::class);

        $action = new CreateMessageAction(
            new MessageInput(
                app: $app,
                company: $this->human()->getCurrentCompany(),
                user: $author,
                type: MessageTypeService::getOrCreate($app, 'note'),
                message: ['content' => $content, 'from_ia' => $fromIa, 'from_agent' => $fromAgent],
                is_public: 1,
            ),
        );
        $action->runWorkflow = false;
        $message = $action->execute();

        ($channel ?? $this->channel($author))->addMessage($message, $author);

        return $message;
    }

    private function human(): Users
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user;
    }
}
