<?php

declare(strict_types=1);

namespace Tests\Approvals;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Approvals\Actions\ApproveAction;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Services\ApproverSelfAssignService;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput as RegisterPostDataDto;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\ApproveAgentMessageAction;
use Kanvas\Social\Messages\Actions\PostChannelMessageAction;
use Kanvas\Social\Messages\Actions\RejectAgentMessageAction;
use Kanvas\Social\Messages\Actions\RequestMessageApprovalAction;
use Kanvas\Social\Messages\Approvals\AgentMessageApprovalHandler;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Support\MessageApproval;
use Kanvas\Users\Models\Users;
use Tests\Stubs\Social\RecordingMessageApprovalHandler;
use Tests\TestCase;

/**
 * The message-approval flow and the generic approvals domain used to be two engines with two records
 * of the same decision. These pin the merge: one approval_requests row is the record, the card payload
 * is its projection, and every decision goes through ApproveAction/RejectAction.
 */
class MessageApprovalUnificationTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'social', 'ecosystem', 'intelligence', 'workflow'];

    protected function setUp(): void
    {
        parent::setUp();

        RecordingMessageApprovalHandler::reset();
    }

    public function testRequestOpensAnApprovalRequestAndRendersAsAPendingCard(): void
    {
        [$message] = $this->heldCard();

        $request = $message->pendingApproval();

        $this->assertInstanceOf(ApprovalRequest::class, $request);
        $this->assertSame(MessageApproval::APPROVAL_TYPE, $request->approval_type);
        $this->assertSame(ApprovalOriginEnum::AGENT, $request->origin);
        $this->assertSame((int) $message->getId(), $request->entity_id);

        // The frontend contract: is_locked plus approval.status === 'pending' is what renders a card.
        $this->assertTrue($message->isLocked());
        $this->assertTrue(MessageApproval::isPending($message));
        $this->assertSame(RecordingMessageApprovalHandler::KIND, MessageApproval::kind($message));
    }

    public function testTheRequestIsBackedByTheOneSharedHandler(): void
    {
        [$message] = $this->heldCard();

        $this->assertSame(
            AgentMessageApprovalHandler::class,
            $message->pendingApproval()?->policy?->handler,
            'every message approval runs through one policy handler; the per-card action is dispatched from it'
        );
    }

    public function testApproveRunsTheCardsHandlerSettlesTheCardAndClosesTheRequest(): void
    {
        [$message, $approver] = $this->heldCard();
        $request = $message->pendingApproval();

        new ApproveAgentMessageAction($message, null, ['choice' => 'b'], $approver)->execute();

        $this->assertTrue(RecordingMessageApprovalHandler::$ran);
        $this->assertSame(
            'b',
            RecordingMessageApprovalHandler::$context['choice'] ?? null,
            "the approver's input must win over the context stored at request time"
        );

        $message->refresh();
        $this->assertFalse($message->isLocked());
        $this->assertSame(MessageApproval::STATUS_APPROVED, MessageApproval::status($message));
        $this->assertSame(ApprovalStatusEnum::APPROVED, $request->refresh()->status);
    }

    /**
     * The thing the old flow could not do. It read the lock, ran the action, then unlocked, so two
     * reviewers who both loaded the card before either pressed approve each passed the lock check and
     * each sent. Here both hold their own still-pending copy of the request and both have a live
     * approver row; only one may reach the action.
     */
    public function testTwoReviewersRacingCannotRunTheActionTwice(): void
    {
        // Two reviewers in the channel; the author is excluded from its own card, so these are the
        // only two approver rows and either one alone satisfies the step.
        $first = $this->makeUser();
        $second = $this->makeUser();
        [$message] = $this->heldCard([$first, $second]);

        $firstView = $message->pendingApproval();
        $secondView = ApprovalRequest::find($firstView?->getId());

        new ApproveAction($firstView, $first)->execute();
        $this->assertSame(1, RecordingMessageApprovalHandler::$runs);

        new ApproveAction($secondView, $second)->execute();

        $this->assertSame(1, RecordingMessageApprovalHandler::$runs, 'the handler must run once per request');
    }

    public function testRejectDiscardsTheDraftAndRecordsWhy(): void
    {
        [$message, $approver] = $this->heldCard();
        $request = $message->pendingApproval();

        $this->assertTrue(new RejectAgentMessageAction($message, 'off-brand', $approver)->execute());

        $this->assertFalse(RecordingMessageApprovalHandler::$ran, 'a rejected card must never run its action');
        $this->assertTrue((bool) $message->fresh()->is_deleted);

        $request->refresh();
        $this->assertSame(ApprovalStatusEnum::REJECTED, $request->status);
        $this->assertSame('off-brand', $request->reason);
        $this->assertSame(MessageApproval::STATUS_REJECTED, MessageApproval::status($message->fresh()));
    }

    /**
     * `channel_members` resolves nobody on tens of thousands of real channels, so a reviewer looking
     * straight at a card was being refused it. An owner or admin may decide anyway — by being written
     * onto the request, never by skipping the check.
     */
    public function testAnOwnerNotOnTheApproverListCanStillApprove(): void
    {
        $resolved = $this->makeUser();
        [$message] = $this->heldCard([$resolved]);
        $request = $message->pendingApproval();

        /** @var Users $owner */
        $owner = auth()->user();
        $this->assertTrue(
            $message->company->isOwner($owner),
            'the acting user owns this company but was not resolved onto the request'
        );

        new ApproveAgentMessageAction($message, null, [], $owner)->execute();

        $this->assertTrue(RecordingMessageApprovalHandler::$ran);
        $this->assertSame(ApprovalStatusEnum::APPROVED, $request->refresh()->status);
        $this->assertSame($owner->getId(), $request->resolved_by_users_id);
    }

    public function testDecidingOnAuthorityIsRecordedAsEvidence(): void
    {
        $resolved = $this->makeUser();
        [$message] = $this->heldCard([$resolved]);
        $request = $message->pendingApproval();

        /** @var Users $owner */
        $owner = auth()->user();
        new ApproveAgentMessageAction($message, null, [], $owner)->execute();

        $evidence = $request->refresh()->metadata['self_assigned_approvers'] ?? [];

        $this->assertCount(1, $evidence, 'a decision nobody was asked for has to be visible afterwards');
        $this->assertSame($owner->getId(), $evidence[0]['users_id']);
        $this->assertSame(ApproverSelfAssignService::OWNER, $evidence[0]['authority']);
        $this->assertArrayHasKey(
            'self_assigned_approvers',
            $request->ledgerPayload(),
            'the evidence has to reach the ledger, not only the row'
        );
    }

    /**
     * The other half: authority is owner/admin of THIS company, not "any authenticated user".
     */
    public function testAStrangerIsStillRefused(): void
    {
        $resolved = $this->makeUser();
        [$message] = $this->heldCard([$resolved]);

        $stranger = $this->makeUser();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not an approver');

        new ApproveAgentMessageAction($message, null, [], $stranger)->execute();
    }

    public function testDecidingOnAMessageWithNoOpenRequestIsRefused(): void
    {
        $message = $this->postMessage();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Message is not pending approval');

        new ApproveAgentMessageAction($message)->execute();
    }

    /**
     * @param list<Users> $extraChannelMembers additional reviewers, for the racing case
     *
     * @return array{0: Message, 1: Users}
     */
    private function heldCard(array $extraChannelMembers = []): array
    {
        $message = $this->postMessage();

        foreach ($extraChannelMembers as $member) {
            $message->channels->first()?->users()->syncWithoutDetaching([$member->getId()]);
        }

        new RequestMessageApprovalAction(
            message: $message,
            kind: RecordingMessageApprovalHandler::KIND,
            handler: RecordingMessageApprovalHandler::class,
            context: ['choice' => 'a'],
        )->execute();

        /** @var Users $approver */
        $approver = auth()->user();

        return [$message->refresh(), $approver];
    }

    /**
     * TestCase::createUser() draws a non-unique `fake()->email`, which collides once a single test
     * makes more than one user.
     */
    private function makeUser(): Users
    {
        return new RegisterUsersAction(
            RegisterPostDataDto::from([
                'email' => fake()->unique()->safeEmail(),
                'password' => fake()->password(8),
                'firstname' => fake()->firstName,
                'lastname' => fake()->lastName,
            ])
        )->execute();
    }

    private function postMessage(): Message
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);
        /** @var Companies $company */
        $company = $user->getCurrentCompany();

        $channel = Channel::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'slug' => 'approval-unification-' . fake()->unique()->uuid(),
            ],
            [
                'name' => 'Approval Unification',
                'description' => 'Approval unification test channel',
                'users_id' => $user->getId(),
            ]
        );

        return new PostChannelMessageAction(
            channel: $channel,
            author: $user,
            verb: 'agent-approval-request',
            content: 'Do the thing?',
            messageTypeName: 'agent-approval-request',
        )->execute();
    }
}
