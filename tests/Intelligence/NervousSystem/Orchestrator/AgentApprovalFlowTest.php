<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem\Orchestrator;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Actions\RequestAgentApprovalAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Orchestrator\Routing\Approval\ProjectRoutingApprovalHandler;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\ApproveAgentMessageAction;
use Kanvas\Social\Messages\Support\MessageApproval;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class AgentApprovalFlowTest extends TestCase
{
    use DatabaseTransactions;

    // ecosystem carries the approval_requests / approval_policies rows the card now projects.
    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'workflow', 'ecosystem'];

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

    private function makeProject(Apps $app, Companies $company, Users $owner, string $title): Project
    {
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $owner->getId(), 'is_active' => true]);

        return new CreateProjectAction(
            ProjectData::from($app, $owner, $company, ['title' => $title, 'agent_id' => $agent->id]),
        )->execute()->refresh();
    }

    public function testRequestCreatesLockedMessageCarryingItsHandler(): void
    {
        [$app, $company, $user] = $this->context();
        $inbox = $this->makeProject($app, $company, $user, 'Inbox');
        /** @var Channel $channel */
        $channel = $inbox->defaultChannel;

        $message = new RequestAgentApprovalAction(
            channel: $channel,
            author: $user,
            content: 'Route this transcript to a project?',
            kind: ProjectRoutingApprovalHandler::KIND,
            handler: ProjectRoutingApprovalHandler::class,
            context: ['project_id' => 123, 'ingest_type' => 'transcript', 'content' => 'body'],
        )->execute();

        $this->assertTrue($message->isLocked());
        $this->assertFalse(
            $message->isPublic(),
            'a draft awaiting sign-off must not be readable in public feeds — is_public defaults to 1'
        );
        $this->assertSame(ProjectRoutingApprovalHandler::class, $message->message['approval']['handler']);
        $this->assertSame('pending', $message->message['approval']['status']);
    }

    public function testApproveRunsTheHandlerForwardsToChosenProjectAndUnlocks(): void
    {
        Bus::fake([WakeAgentForProjectJob::class]);

        [$app, $company, $user] = $this->context();
        $inbox = $this->makeProject($app, $company, $user, 'Inbox');
        $target = $this->makeProject($app, $company, $user, 'Acme');
        /** @var Channel $channel */
        $channel = $inbox->defaultChannel;

        $message = new RequestAgentApprovalAction(
            channel: $channel,
            author: $user,
            content: 'Route?',
            kind: ProjectRoutingApprovalHandler::KIND,
            handler: ProjectRoutingApprovalHandler::class,
            // suggested a different project; the human redirects to $target on approval.
            context: ['project_id' => 999, 'ingest_type' => 'transcript', 'content' => 'Ship Friday; assign design.'],
        )->execute();

        $approved = new ApproveAgentMessageAction(
            $message,
            null,
            ['project_id' => (int) $target->id],
        )->execute();

        $this->assertFalse($approved->isLocked());
        $this->assertSame(
            MessageApproval::STATUS_APPROVED,
            MessageApproval::status($approved),
            'the card renders off approval.status, so an approved draft that still says pending keeps '
            . 'offering a live Approve button'
        );

        // The held signal was forwarded to the human-chosen project (not the suggested 999).
        $this->assertDatabaseHas('nervous_system_events', [
            'source_entity_type' => Project::class,
            'source_entity_id' => $target->id,
            'event_type' => 'project.transcript.received',
        ], 'intelligence');
    }

    public function testRequestRejectsAHandlerThatDoesNotImplementTheContract(): void
    {
        [$app, $company, $user] = $this->context();
        $inbox = $this->makeProject($app, $company, $user, 'Inbox');
        /** @var Channel $channel */
        $channel = $inbox->defaultChannel;

        $before = $channel->messages()->count();

        try {
            new RequestAgentApprovalAction(
                channel: $channel,
                author: $user,
                content: 'x',
                kind: 'demo',
                handler: self::class, // not an AgentApprovalHandler
            )->execute();
            $this->fail('an unusable handler must be refused');
        } catch (ValidationException) {
            // expected
        }

        // The refusal has to land before the message is posted. Rejecting it afterwards would leave an
        // ungated draft on the channel that nothing can ever approve.
        $this->assertSame($before, $channel->messages()->count());
    }
}
