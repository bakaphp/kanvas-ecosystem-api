<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Jobs\RespondToMentionJob;
use Kanvas\Intelligence\Agents\Listeners\RespondToAgentMentionListener;
use Kanvas\Intelligence\Agents\Models\Agent;
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

    public function testAgentAuthoredMessageDoesNotWakeAnotherAgent(): void
    {
        Bus::fake([RespondToMentionJob::class, WakeAgentForProjectJob::class]);
        [$app, $company, $user] = $this->context();
        $pm = $this->makeAgent($app, $company, $user);
        $project = $this->makeProject($app, $company, $user, $pm);

        // from_ia message that "mentions" the PM agent — the guard must stop a wake.
        $message = $this->postMessage($project, $user, fromIa: true);

        new RespondToAgentMentionListener()->handle(
            new MessageMentionsStoredEvent($message, [(int) $pm->user_id]),
        );

        Bus::assertNotDispatched(RespondToMentionJob::class);
        Bus::assertNotDispatched(WakeAgentForProjectJob::class);
    }
}
