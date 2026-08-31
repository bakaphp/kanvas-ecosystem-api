<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Jobs\RespondToMentionJob;
use Kanvas\Intelligence\Agents\Listeners\RespondToAgentMentionListener;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\AgentConversationBudget;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\Actions\PostProjectMessageAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Social\Messages\Events\MessageMentionsStoredEvent;
use Kanvas\Social\Messages\Listeners\NotifyMentionedUsersListener;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Notifications\UserMentionedNotification;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class MentionNotificationTest extends TestCase
{
    // Inert without the trait: declared alone, every row this test writes COMMITS. These create
    // agents on the shared auth user, and a leaked agent makes Agent::fromUser() call a human an agent.
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'workflow'];

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
            ->create(['user_id' => $user->getId(), 'is_active' => true]);
    }

    private function makeProject(Apps $app, Companies $company, Users $user, Agent $pm): Project
    {
        return new CreateProjectAction(
            ProjectData::from(
                $app,
                $user,
                $company,
                ['title' => 'Mention project', 'agent_id' => $pm->id],
            ),
        )->execute();
    }

    private function postMessage(Project $project, Users $author, bool $fromIa = false): Message
    {
        // Plain content (no '@') so the observer doesn't fire the real parse pipeline — we drive
        // the listeners explicitly with known ids.
        return new PostProjectMessageAction(
            project: $project,
            verb: 'test-post',
            content: 'status update',
            author: $author,
            fromIa: $fromIa,
        )->execute();
    }

    public function testHumanMentionNotifiesTheUser(): void
    {
        Notification::fake();
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user, $this->makeAgent($app, $company, $user));

        $mentioned = Users::factory()->create();
        $message = $this->postMessage($project, $user);

        new NotifyMentionedUsersListener()->handle(
            new MessageMentionsStoredEvent($message, [(int) $mentioned->getId()]),
        );

        Notification::assertSentTo($mentioned, UserMentionedNotification::class);
    }

    public function testAuthorIsNotNotifiedForSelfMention(): void
    {
        Notification::fake();
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user, $this->makeAgent($app, $company, $user));

        $message = $this->postMessage($project, $user);

        new NotifyMentionedUsersListener()->handle(
            new MessageMentionsStoredEvent($message, [(int) $user->getId()]),
        );

        Notification::assertNothingSent();
    }

    public function testMentionNotificationUsesDedicatedTemplate(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user, $this->makeAgent($app, $company, $user));
        $message = $this->postMessage($project, $user);

        $notification = new UserMentionedNotification($message, $user);

        $this->assertSame('email-user-mention', $notification->getTemplateName());
    }

    public function testMentionEmailTemplateIncludesMessageBody(): void
    {
        $rendered = Blade::render(
            File::get(resource_path('views/emails/userMentioned.blade.php')),
            [
                'user' => (object) ['firstname' => 'Johnny'],
                'fromUserName' => 'Roven PM',
                'body' => 'please review the new design',
            ],
        );

        $this->assertStringContainsString('Johnny', $rendered);
        $this->assertStringContainsString('Roven PM', $rendered);
        $this->assertStringContainsString('mentioned you', $rendered);
        $this->assertStringContainsString('please review the new design', $rendered);
    }

    public function testAgentMentionIsNotHandledByHumanListener(): void
    {
        Notification::fake();
        [$app, $company, $user] = $this->context();
        $agentUser = Users::factory()->create();
        $agent = $this->makeAgent($app, $company, $agentUser);
        $project = $this->makeProject($app, $company, $user, $this->makeAgent($app, $company, $user));

        $message = $this->postMessage($project, $user);

        new NotifyMentionedUsersListener()->handle(
            new MessageMentionsStoredEvent($message, [(int) $agent->user_id]),
        );

        // Agent users are woken by RespondToAgentMentionListener, never notified as humans.
        Notification::assertNothingSent();
    }

    /**
     * An agent naming another agent reaches it — a handoff is the point, not a loop.
     *
     * This used to assert the opposite: agent-authored mentions were dropped outright as an anti-loop
     * guard. That also silenced every legitimate handoff (plan 25148 — a worker finished step 1, wrote
     * "@agenthtmltemplatedesigner you may now proceed", and the next task stayed `pending`).
     */
    public function testAgentAuthoredMessageWakesTheMentionedAgent(): void
    {
        Bus::fake([RespondToMentionJob::class, WakeAgentForProjectJob::class]);
        [$app, $company, $user] = $this->context();

        // A dedicated user for the PM: the shared auth user backs thousands of agents in the test DB,
        // so Agent::fromUser() would resolve to an arbitrary one and the project route would decline.
        $pm = $this->makeAgent($app, $company, Users::factory()->create());
        $project = $this->makeProject($app, $company, $user, $pm);

        $message = $this->postMessage($project, $user, fromIa: true);

        new RespondToAgentMentionListener()->handle(
            new MessageMentionsStoredEvent($message, [(int) $pm->user_id]),
        );

        Bus::assertDispatched(WakeAgentForProjectJob::class);
    }

    /**
     * The anti-loop guarantee, now carried by the budget instead of a blanket refusal: two agents get
     * MAX_HOPS between them on a channel and then the exchange stops on its own.
     */
    public function testAgentToAgentMentionsStopOnceTheHopBudgetIsSpent(): void
    {
        Bus::fake([RespondToMentionJob::class, WakeAgentForProjectJob::class]);
        [$app, $company, $user] = $this->context();
        $pm = $this->makeAgent($app, $company, Users::factory()->create());
        $project = $this->makeProject($app, $company, $user, $pm);

        for ($hop = 0; $hop < AgentConversationBudget::MAX_HOPS + 3; $hop++) {
            new RespondToAgentMentionListener()->handle(
                new MessageMentionsStoredEvent(
                    $this->postMessage($project, $user, fromIa: true),
                    [(int) $pm->user_id],
                ),
            );
        }

        Bus::assertDispatchedTimes(WakeAgentForProjectJob::class, AgentConversationBudget::MAX_HOPS);
    }
}
