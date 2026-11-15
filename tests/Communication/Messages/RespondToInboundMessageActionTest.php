<?php

declare(strict_types=1);

namespace Tests\Communication\Messages;

use ErrorException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Communication\Channels\Email\EmailComposer;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Messages\Actions\AgentChannelResponderAction;
use Kanvas\Communication\Messages\Actions\DeliverAgentReplyAction;
use Kanvas\Communication\Messages\Actions\RespondToInboundMessageAction;
use Kanvas\Communication\Messages\Activities\RespondToInboundMessageActivity;
use Kanvas\Communication\Messages\DataTransferObject\TransportResult;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Exceptions\InboundNotAnsweredException;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\Jobs\SendUnrespondedAgentMessageJob;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\ApproveAgentMessageAction;
use Kanvas\Social\Messages\Approvals\AgentMessageApprovalHandler;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Support\MessageApproval;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\Communication\Fixtures\FakeTransport;
use Tests\Stubs\Intelligence\PartlessNeuronAgentStub;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\Stubs\Intelligence\StructuredNeuronAgentStub;
use Tests\TestCase;

/**
 * The answer to an inbound message: whatever medium it arrived on, the agent that owns the
 * connection answers through it, and the delivery is recorded on the reply the agent filed.
 */
final class RespondToInboundMessageActionTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social', 'intelligence'];

    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = $this->useFakeProvider();
        $this->currentCompany()->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, auth()->user()->getId());
    }

    /**
     * Company settings outlive the test transaction (Redis and the ecosystem connection).
     */
    protected function tearDown(): void
    {
        $this->currentCompany()->del(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value);
        $this->currentCompany()->del(IntelligenceConfigurationEnum::AGENT_AI_MODE->value);

        parent::tearDown();
    }

    public function testTheAnswerLeavesThroughTheConversationsConnection(): void
    {
        $connection = $this->defaultSmsConnection(number: '+18095550600');
        [$channel, $inbound] = $this->inboundOn($connection, '+18095550601');

        $result = $this->respond($channel, $inbound);

        $delivery = Delivery::query()->where('connection_id', $connection->getId())->sole();
        $reply = Message::query()->findOrFail($delivery->message_id);

        // The first text to a number carries the opt-out clause.
        $this->assertSame("Hola Mundo\n\nReply STOP to opt out.", $result['response'] ?? null);
        $this->assertSame($result['response'], $this->transport->sent[0]['text']);
        $this->assertSame(DeliveryStatusEnum::ACCEPTED, $delivery->status);
        $this->assertSame('+18095550601', $delivery->to_address);
        $this->assertTrue((bool) $reply->message['from_ia']);
        $this->assertSame($inbound->messageType->verb, $reply->messageType->verb, 'filed as the kind of message it answers');
        $this->assertCount(1, $this->transport->sent);
    }

    public function testStructuredRepliesKeepTheirEnvelopeOnTheFiledMessage(): void
    {
        $connection = $this->defaultSmsConnection(number: '+18095550607');
        [$channel, $inbound] = $this->inboundOn($connection, '+18095550608');

        $result = $this->respond($channel, $inbound, StructuredNeuronAgentStub::class);

        $delivery = Delivery::query()->where('connection_id', $connection->getId())->sole();
        $reply = Message::getById($delivery->message_id, $this->currentApp());

        $this->assertSame("Hola Mundo\n\nReply STOP to opt out.", $this->transport->sent[0]['text']);
        $this->assertSame('Education accelerates classroom construction in El Seibo', $reply->message['response_json']['title'] ?? null);
        $this->assertSame(['National', 'Education'], $reply->message['response_json']['categories'] ?? null);
        $this->assertSame('draft', $reply->message['response_json']['status'] ?? null);
    }

    public function testAConnectionNobodyOwnsIsNotAnswered(): void
    {
        $connection = $this->defaultSmsConnection(number: '+18095550611');
        [$channel, $inbound] = $this->inboundOn($connection, '+18095550612');
        $this->agent(SalesNeuronAgentStub::class);

        $result = new RespondToInboundMessageAction($channel, $inbound)->execute();

        $this->assertSame('No agent owns +18095550611', $result['message']);
        $this->assertSame('no_owner', $result['reason']);
        $this->assertSame([], $this->transport->sent);
    }

    /**
     * The app default rule's activity: a message on a connection nobody owns is not a turn and is
     * left as filed; a turn that is refused leaves its reason on the message, where the conversation
     * shows it, and is recorded as skipped.
     */
    public function testATurnNobodyAnswersLeavesItsReasonOnTheMessage(): void
    {
        $connection = $this->defaultSmsConnection(number: '+18095550622');
        [$channel, $inbound] = $this->inboundOn($connection, '+18095550623');

        [$activity, $result] = $this->runActivity($channel, $inbound);

        $this->assertTrue($result['skipped']);
        $this->assertSame('not_owned', $result['message']);
        $this->assertSame(StatusEnum::SKIPPED, $activity->workflowStatus());
        $this->assertFalse($inbound->hasTag(['communication-no_owner']), 'a message nobody owns is left as filed');

        $this->own($connection, $this->agent(SalesNeuronAgentStub::class));
        $connection->forceFill(['enabled' => false])->saveOrFail();

        [$activity, $result] = $this->runActivity($channel, $inbound);

        $this->assertTrue($result['skipped']);
        $this->assertSame('connection_disabled', $result['message']);
        $this->assertSame(StatusEnum::SKIPPED, $activity->workflowStatus());
        $this->assertTrue($inbound->hasTag(['communication-connection_disabled']));
        $this->assertSame([], $this->transport->sent);
    }

    public function testTheActivityAnswersAsTheOwnerAndIsNeverRetried(): void
    {
        $connection = $this->defaultSmsConnection(number: '+18095550630');
        [$channel, $inbound] = $this->inboundOn($connection, '+18095550631');
        $this->own($connection, $this->agent(SalesNeuronAgentStub::class));

        [$activity, $result] = $this->runActivity($channel, $inbound);

        $this->assertSame(1, $activity->tries, 'a retry after the send would message the customer twice');
        $this->assertNull($activity->workflowStatus());
        $this->assertArrayNotHasKey('skipped', $result);
        $this->assertCount(1, $this->transport->sent);
        $this->assertSame('+18095550631', $this->transport->sent[0]['to']);
    }

    /**
     * Our own side of the conversation fires the event for the rules that listen; it is never answered.
     */
    public function testTheActivityNeverAnswersOurOwnSide(): void
    {
        $connection = $this->defaultSmsConnection(number: '+18095550632');
        [$channel, $inbound] = $this->inboundOn($connection, '+18095550633');
        $this->own($connection, $this->agent(SalesNeuronAgentStub::class));
        $inbound->message = [...$inbound->message, 'from_me' => true];
        $inbound->saveOrFail();

        [$activity, $result] = $this->runActivity($channel, $inbound);

        $this->assertTrue($result['skipped']);
        $this->assertSame(StatusEnum::SKIPPED, $activity->workflowStatus());
        $this->assertSame([], $this->transport->sent);
    }

    public function testTwoAgentsEachAnswerTheirOwnNumber(): void
    {
        $first = $this->defaultSmsConnection(number: '+18095550613');
        $second = $this->connection('fake', '+18095550614');
        $this->own($first, $this->agent(SalesNeuronAgentStub::class));
        $this->own($second, $this->agent(StructuredNeuronAgentStub::class));
        [$channel, $inbound] = $this->inboundOn($second, '+18095550615');

        new RespondToInboundMessageAction($channel, $inbound)->execute();

        $delivery = Delivery::query()->where('connection_id', $second->getId())->sole();
        $reply = Message::getById($delivery->message_id, $this->currentApp());

        $this->assertFalse(Delivery::query()->where('connection_id', $first->getId())->exists());
        $this->assertSame('draft', $reply->message['response_json']['status'] ?? null, 'answered by the second number\'s agent');
    }

    public function testAnAgentFromAnotherCompanyCannotOwnAConnection(): void
    {
        $connection = $this->defaultSmsConnection(number: '+18095550609');
        [$channel, $inbound] = $this->inboundOn($connection, '+18095550610');
        $agent = $this->agent(SalesNeuronAgentStub::class);
        $agent->forceFill(['companies_id' => $this->otherCompany()->getId()])->saveOrFail();
        $this->own($connection, $agent);

        $result = new RespondToInboundMessageAction($channel, $inbound)->execute();

        $this->assertSame('No agent owns +18095550609', $result['message']);
        $this->assertSame([], $this->transport->sent);
        $this->assertFalse(Delivery::query()->where('connection_id', $connection->getId())->exists());
    }

    /**
     * A colleague who wrote to the agent is neither a person nor a lead, and is still answered.
     */
    public function testAColleagueIsAnsweredAsThemselves(): void
    {
        $connection = $this->defaultSmsConnection(number: '+18095550616');
        $this->own($connection, $this->agent(SalesNeuronAgentStub::class));
        $user = auth()->user();
        $channel = Channel::create([
            'users_id' => $user->getId(),
            'apps_id' => $this->currentApp()->getId(),
            'companies_id' => $this->currentCompany()->getId(),
            'slug' => 'fake-colleague-' . uniqid(),
            'name' => 'Colleague',
            'description' => 'Colleague',
            'entity_namespace' => Users::class,
            'entity_id' => $user->getId(),
        ]);
        $inbound = Message::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->create([
                'users_id' => $user->getId(),
                'message' => [
                    'content' => 'Can you pull the report?',
                    'from_me' => false,
                    'chat_jid' => '+18095550617',
                    'communication' => [
                        'connection_id' => $connection->getId(),
                        'channel' => ChannelTypeEnum::SMS->value,
                        'thread' => '+18095550617',
                        'activation_version' => $connection->activation_version,
                    ],
                ],
            ]);
        $inbound->addEntity($user);
        $channel->addMessage($inbound);

        $result = new RespondToInboundMessageAction($channel, $inbound->fresh())->execute();

        $this->assertArrayHasKey('response', $result, json_encode($result));
        $this->assertSame('+18095550617', $this->transport->sent[0]['to']);
    }

    /**
     * A message that did not arrive on a connection has no owner to answer it.
     */
    public function testAMessageOnNoConnectionIsLeftAlone(): void
    {
        $lead = $this->leadOf($this->personWithCellphone('+18095550602'));
        $channel = $this->conversationChannel($lead);
        $inbound = $this->fileInConversation(
            $channel,
            $lead,
            'fake-sms',
            ['content' => 'Hi', 'from_me' => false]
        );

        $result = $this->respond($channel, $inbound);

        $this->assertSame('The message did not arrive on a connection', $result['message']);
        $this->assertSame([], $this->transport->sent);
    }

    public function testATurnForAConnectionSwitchedOffSendsNothing(): void
    {
        $connection = $this->defaultSmsConnection(number: '+18095550603');
        [$channel, $inbound] = $this->inboundOn($connection, '+18095550604');
        $connection->forceFill(['enabled' => false])->saveOrFail();

        $result = $this->respond($channel, $inbound);

        $this->assertStringContainsString('connection_disabled', $result['message']);
        $this->assertSame('connection_disabled', $result['reason']);
        $this->assertSame([], $this->transport->sent);
    }

    /**
     * Switching a connection off cancels the turns queued on it; switching it back on must not
     * quietly release them.
     */
    public function testATurnQueuedBeforeAnOffAndOnIsStale(): void
    {
        $connection = $this->defaultSmsConnection(number: '+18095550624');
        [$channel, $inbound] = $this->inboundOn($connection, '+18095550625');
        $connection->forceFill(['activation_version' => $connection->activation_version + 1])->saveOrFail();

        $result = $this->respond($channel, $inbound);

        $this->assertSame('stale_activation', $result['reason']);
        $this->assertSame([], $this->transport->sent);
    }

    /**
     * The responder is the one place that says whether a message may be answered, so an agent the
     * connection no longer belongs to is refused however the turn reached it.
     */
    public function testAnAgentThatNoLongerOwnsTheConnectionIsRefused(): void
    {
        $connection = $this->defaultSmsConnection(number: '+18095550626');
        [$channel, $inbound] = $this->inboundOn($connection, '+18095550627');
        $former = $this->agent(SalesNeuronAgentStub::class);
        $this->own($connection, $this->agent(SalesNeuronAgentStub::class));

        try {
            new AgentChannelResponderAction($channel, $inbound, $former);
            $this->fail('An agent that does not own the connection must not answer on it.');
        } catch (InboundNotAnsweredException $refused) {
            $this->assertSame('owner_changed', $refused->reason);
        }
    }

    /**
     * A support-mode lead's turn waits for the salesperson first. When the wait is over the
     * connection is asked again, exactly as for a live turn, and a message whose number changed
     * hands in between goes to the new owner.
     */
    public function testADelayedTurnAnswersOnlyWhileTheConnectionStillAllowsIt(): void
    {
        $connection = $this->defaultSmsConnection(number: '+18095550628');
        [$channel, $inbound, $lead] = $this->inboundOn($connection, '+18095550629');
        $agent = $this->agent(SalesNeuronAgentStub::class);
        $this->own($connection, $agent);
        $lead->set(IntelligenceConfigurationEnum::AI_MODE->value, IntelligenceModeEnum::SUPPORT->value);
        $lead->set(LeadConfigurationEnum::AI_MODE_IS_MANUAL->value, true);
        $this->assertTrue($lead->isAiSupport());
        $connection->forceFill(['enabled' => false])->saveOrFail();

        $this->delayedTurn($channel, $inbound, $agent)->handle();

        $this->assertSame([], $this->transport->sent, 'switched off while the turn waited');

        $connection->forceFill(['enabled' => true])->saveOrFail();
        $this->own($connection, $this->agent(SalesNeuronAgentStub::class));

        $this->delayedTurn($channel, $inbound, $agent)->handle();

        // The wait for a person is over, so the new owner answers at once instead of waiting again.
        $this->assertCount(1, $this->transport->sent, 'handed to the new owner, answered once');
        $this->assertSame('+18095550629', $this->transport->sent[0]['to']);
    }

    /**
     * A turn queued before a deploy names the responder class of that release. When the class is
     * gone the wait is still over: the agent that owns the connection answers, and the job does not
     * die on a class that no longer exists.
     */
    public function testADelayedTurnQueuedWithAResponderThatNoLongerExistsIsAnsweredByTheOwner(): void
    {
        $connection = $this->defaultSmsConnection(number: '+18095550640');
        [$channel, $inbound, $lead] = $this->inboundOn($connection, '+18095550641');
        $agent = $this->agent(SalesNeuronAgentStub::class);
        $this->own($connection, $agent);
        $lead->set(IntelligenceConfigurationEnum::AI_MODE->value, IntelligenceModeEnum::SUPPORT->value);
        $lead->set(LeadConfigurationEnum::AI_MODE_IS_MANUAL->value, true);

        new SendUnrespondedAgentMessageJob(
            $channel,
            $inbound,
            $agent,
            $this->currentApp(),
            actionClass: 'Kanvas\Connectors\Twilio\Actions\TwilioAgentChannelResponderAction'
        )->handle();

        $this->assertCount(1, $this->transport->sent);
        $this->assertSame('+18095550641', $this->transport->sent[0]['to']);
    }

    /**
     * The recipient unsubscribed with the provider directly. Expected, so it is a note on the lead
     * and not a fault the job retries into the provider again.
     */
    public function testARecipientWhoUnsubscribedWithTheProviderLeavesANote(): void
    {
        $this->transport->sendResult = TransportResult::failed('21610', 'unsubscribed', recipientOptedOut: true);
        $connection = $this->defaultSmsConnection(number: '+18095550605');
        [$channel, $inbound] = $this->inboundOn($connection, '+18095550606');

        $this->respond($channel, $inbound);

        $this->assertTrue(Delivery::query()->where('connection_id', $connection->getId())->sole()->recipientOptedOut());
        $this->assertTrue(
            Message::query()
                ->where('companies_id', $this->currentCompany()->getId())
                ->where('message', 'like', '%has opted out of messages%')
                ->exists()
        );
    }

    /**
     * Every email of a lead's thread takes its subject from the lead; one that wrote in cold gives
     * the thread its subject.
     */
    public function testAnEmailIsAnsweredUnderTheLeadsThreadSubject(): void
    {
        $connection = $this->connection('fake', 'sales@kanvas.test');
        [$channel, $inbound, $lead] = $this->emailOn($connection, 'ana@customer.test', 'RE: Availability');

        $this->respond($channel, $inbound);

        $this->assertSame('Availability', $lead->get(LeadConfigurationEnum::EMAIL_THREAD_SUBJECT->value));
        $this->assertSame('Re: Availability', $this->transport->sent[0]['content']->subject);
        $this->assertSame('Hola Mundo', $this->transport->sent[0]['text'], 'no opt-out clause on an email');
    }

    public function testTheSubjectTheOutreachGaveTheThreadIsKept(): void
    {
        $connection = $this->connection('fake', 'sales@kanvas.test');
        [$channel, $inbound, $lead] = $this->emailOn($connection, 'ana@customer.test', 'Something else');
        $lead->set(LeadConfigurationEnum::EMAIL_THREAD_SUBJECT->value, 'Your quote');

        $this->respond($channel, $inbound);

        $this->assertSame('Re: Your quote', $this->transport->sent[0]['content']->subject);
        $this->assertSame('Your quote', $lead->get(LeadConfigurationEnum::EMAIL_THREAD_SUBJECT->value));
    }

    /**
     * From the company's address the answer is the company's email; from its own mailbox the agent
     * writes the text as it is.
     */
    public function testOnlyAnAgentsOwnMailboxWritesTheTextAsItIs(): void
    {
        $company = $this->connection('fake', 'sales@kanvas.test');
        [$channel, $inbound] = $this->emailOn($company, 'ana@customer.test');
        $this->respond($channel, $inbound);

        $mailbox = $this->connection('fake', 'sofia@kanvas.test', ['config' => [Connection::PERSONAL => true]]);
        [$ownChannel, $ownInbound] = $this->emailOn($mailbox, 'luis@customer.test');
        $this->respond($ownChannel, $ownInbound);

        $this->assertSame(EmailComposer::REPLY_TEMPLATE, $this->transport->sent[0]['content']->email?->template);
        $this->assertTrue($this->transport->sent[0]['content']->email->companySignature);
        $this->assertNull($this->transport->sent[1]['content']->email);
    }

    /**
     * An email can wait for a person to read it first; approving it sends the same draft in the
     * conversation it answers.
     */
    // The approval mode is a company setting every paratest process reads.
    #[Group('serial')]
    public function testAnEmailReplyIsHeldForApprovalAndSentWhenApproved(): void
    {
        $this->currentCompany()->set(IntelligenceConfigurationEnum::AGENT_AI_MODE->value, IntelligenceModeEnum::APPROVAL->value);
        $connection = $this->connection('fake', 'sales@kanvas.test');
        [$channel, $inbound] = $this->emailOn($connection, 'ana@customer.test');

        $this->respond($channel, $inbound);

        $draft = Message::getById($channel->fresh()->last_message_id, $this->currentApp());

        $this->assertTrue($draft->isLocked());
        $this->assertSame([], $this->transport->sent);

        $approved = new ApproveAgentMessageAction($draft, 'Edited by a person')->execute();

        $this->assertFalse($approved->isLocked());
        $this->assertSame('Edited by a person', $this->transport->sent[0]['text']);
        $this->assertSame('ana@customer.test', $this->transport->sent[0]['to']);
        $this->assertSame($draft->getId(), (int) Delivery::query()->where('connection_id', $connection->getId())->sole()->message_id);
    }

    // The approval mode is a company setting every paratest process reads.
    #[Group('serial')]
    public function testATextIsNeverHeldForApproval(): void
    {
        $this->currentCompany()->set(IntelligenceConfigurationEnum::AGENT_AI_MODE->value, IntelligenceModeEnum::APPROVAL->value);
        $connection = $this->defaultSmsConnection(number: '+18095550620');
        [$channel, $inbound] = $this->inboundOn($connection, '+18095550621');

        $this->respond($channel, $inbound);

        $this->assertCount(1, $this->transport->sent);
    }

    // The approval mode is a company setting every paratest process reads.
    #[Group('serial')]
    public function testAnApprovedDraftThatAnswersNothingIsRefused(): void
    {
        $this->currentCompany()->set(IntelligenceConfigurationEnum::AGENT_AI_MODE->value, IntelligenceModeEnum::APPROVAL->value);
        $connection = $this->connection('fake', 'sales@kanvas.test');
        [$channel, $inbound] = $this->emailOn($connection, 'ana@customer.test');
        $this->respond($channel, $inbound);
        $draft = Message::getById($channel->fresh()->last_message_id, $this->currentApp());
        $draft->addMessage([DeliverAgentReplyAction::ANSWERS_KEY => 0]);

        $this->expectException(ValidationException::class);

        new ApproveAgentMessageAction($draft->fresh())->execute();
    }

    /**
     * Approving again after a failure sends again, so only a send that certainly did not leave may
     * fail the approval: one whose outcome was lost is settled, and is never sent a second time.
     */
    // The approval mode is a company setting every paratest process reads.
    #[Group('serial')]
    public function testAnApprovalWhoseSendOutcomeWasLostIsSettledAndNeverSentAgain(): void
    {
        $this->currentCompany()->set(IntelligenceConfigurationEnum::AGENT_AI_MODE->value, IntelligenceModeEnum::APPROVAL->value);
        $connection = $this->connection('fake', 'sales@kanvas.test');
        [$channel, $inbound] = $this->emailOn($connection, 'ana@customer.test');
        $this->respond($channel, $inbound);
        $draft = Message::getById($channel->fresh()->last_message_id, $this->currentApp());
        $request = $draft->pendingApproval();
        $this->transport->sendException = new RuntimeException('The provider stopped answering');

        $approved = new ApproveAgentMessageAction($draft)->execute();

        $delivery = Delivery::query()->where('connection_id', $connection->getId())->sole();

        $this->assertSame(DeliveryStatusEnum::UNKNOWN, $delivery->status);
        $this->assertFalse($approved->isLocked());
        $this->assertSame(MessageApproval::STATUS_APPROVED, MessageApproval::status($approved));
        $this->assertNull($request->refresh()->handlerError(), 'nothing left to retry');

        $this->transport->sendException = null;
        $result = new AgentMessageApprovalHandler()->handle($request, auth()->user());

        $this->assertSame(DeliveryStatusEnum::UNKNOWN->value, $result['delivery_status']);
        $this->assertCount(1, $this->transport->sent, 'the provider was called once');
    }

    // The approval mode is a company setting every paratest process reads.
    #[Group('serial')]
    public function testAnApprovedDraftThatCertainlyDidNotLeaveCanBeSentAgain(): void
    {
        $this->currentCompany()->set(IntelligenceConfigurationEnum::AGENT_AI_MODE->value, IntelligenceModeEnum::APPROVAL->value);
        $connection = $this->connection('fake', 'sales@kanvas.test');
        [$channel, $inbound] = $this->emailOn($connection, 'ana@customer.test');
        $this->respond($channel, $inbound);
        $draft = Message::getById($channel->fresh()->last_message_id, $this->currentApp());
        $request = $draft->pendingApproval();
        $this->transport->sendResult = TransportResult::failed('mailbox_unavailable', 'The mailbox is unavailable');

        try {
            new ApproveAgentMessageAction($draft)->execute();
            $this->fail('A send that did not leave must come back as an error.');
        } catch (ValidationException $failure) {
            $this->assertStringContainsString('The mailbox is unavailable', $failure->getMessage());
        }

        $this->assertTrue($draft->fresh()->isLocked());
        $this->assertTrue(MessageApproval::isPending($draft->fresh()));

        $this->transport->sendResult = null;
        $result = new AgentMessageApprovalHandler()->handle($request->refresh(), auth()->user());

        $this->assertSame(DeliveryStatusEnum::ACCEPTED->value, $result['delivery_status']);
        $this->assertCount(2, $this->transport->sent);
        $this->assertFalse($draft->fresh()->isLocked());
    }

    /**
     * The fallback copy is written for staff; a failed email turn sends nothing and fails the job.
     */
    public function testAFailedEmailTurnSendsNothing(): void
    {
        $connection = $this->connection('fake', 'sales@kanvas.test');
        [$channel, $inbound] = $this->emailOn($connection, 'ana@customer.test');
        $failed = false;

        try {
            $this->respond($channel, $inbound, PartlessNeuronAgentStub::class);
        } catch (ErrorException) {
            $failed = true;
        }

        $this->assertTrue($failed, 'the failure must reach the job, not become a reply');
        $this->assertSame([], $this->transport->sent);
    }

    /**
     * @return array{Channel, Message, Lead}
     */
    private function emailOn(Connection $connection, string $from, string $subject = 'Availability'): array
    {
        $lead = $this->leadOf($this->personWithCellphone('+1809555' . random_int(1000, 9999)));
        $channel = $this->conversationChannel($lead, 'fake-email-');

        $inbound = $this->fileInConversation(
            $channel,
            $lead,
            'fake-email',
            [
                'content' => 'Is it still available?',
                'from_me' => false,
                'chat_jid' => $from,
                'raw_data' => ['sender' => $from, 'recipient' => $connection->account_key, 'subject' => $subject],
                'communication' => [
                    'connection_id' => $connection->getId(),
                    'channel' => ChannelTypeEnum::EMAIL->value,
                    'thread' => $from,
                    'activation_version' => $connection->activation_version,
                ],
            ]
        );

        return [$channel, $inbound, $lead];
    }

    /**
     * @return array{Channel, Message, Lead}
     */
    private function inboundOn(Connection $connection, string $from): array
    {
        $lead = $this->leadOf($this->personWithCellphone($from));
        $channel = $this->conversationChannel($lead);

        $inbound = $this->fileInConversation(
            $channel,
            $lead,
            'fake-sms',
            [
                'content' => 'Is it still available?',
                'from_me' => false,
                'chat_jid' => $from,
                'communication' => [
                    'connection_id' => $connection->getId(),
                    'channel' => ChannelTypeEnum::SMS->value,
                    'thread' => $from,
                    'activation_version' => $connection->activation_version,
                ],
            ]
        );

        return [$channel, $inbound, $lead];
    }

    private function delayedTurn(Channel $channel, Message $inbound, Agent $agent): SendUnrespondedAgentMessageJob
    {
        return new SendUnrespondedAgentMessageJob(
            $channel,
            $inbound,
            $agent,
            $this->currentApp(),
            actionClass: AgentChannelResponderAction::class
        );
    }

    /**
     * @return array{0: RespondToInboundMessageActivity, 1: array<string, mixed>}
     */
    private function runActivity(Channel $channel, Message $inbound): array
    {
        $activity = new RespondToInboundMessageActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        );

        return [$activity, $activity->execute($channel, $this->currentApp(), ['message' => $inbound])];
    }

    private function respond(Channel $channel, Message $inbound, string $handler = SalesNeuronAgentStub::class): array
    {
        $connection = Connection::forFiledMessage($inbound);

        if ($connection !== null) {
            $this->own($connection, $this->agent($handler));
        }

        return new RespondToInboundMessageAction($channel, $inbound)->execute();
    }

    private function own(Connection $connection, Agent $agent): void
    {
        $connection->forceFill(['config' => [...($connection->config ?? []), 'agent_id' => $agent->getId()]])->saveOrFail();
    }

    private function agent(string $handler): Agent
    {
        $agentType = AgentType::factory()
            ->withAppId($this->currentApp()->getId())
            ->create(['name' => 'Sales (Neuron Test)', 'provider' => 'neuron', 'handler' => $handler]);

        return Agent::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->create([
                'name' => 'Sofia',
                'agent_type_id' => $agentType->getId(),
                'instructions' => 'Always respond Hola Mundo',
                'output_format' => 'plain text',
            ]);
    }
}
