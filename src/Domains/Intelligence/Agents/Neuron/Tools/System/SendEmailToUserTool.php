<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\System;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Kanvas\Apps\Support\SmtpRuntimeConfiguration;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Intelligence\Sessions\Services\UserAgentChannelService;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\Notifications\KanvasMailable;
use Kanvas\Notifications\Support\MarkdownEmailRenderer;
use Kanvas\Social\Messages\Actions\PostChannelMessageAction;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Email Teammate', category: 'ecosystem')]
class SendEmailToUserTool extends Tool
{
    protected string $name = 'send_email_to_user';

    protected ?string $description = 'Send an email to an internal teammate — a Kanvas user in this company — identified by '
        . 'their email address. Use it when someone asks you to email a colleague / staff member. The '
        . 'recipient must be a member of this company; you cannot email arbitrary outside addresses. This '
        . 'is NOT for emailing a prospect/customer on a lead (use send_lead_email for that).';

    public const string LEDGER_EVENT = 'agent.email.sent_to_user';

    public const string CHAT_VERB = 'agent-email';

    public function __construct(private readonly ?Agent $agent = null)
    {
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'recipient_email',
                type: PropertyType::STRING,
                description: "The teammate's email address. Must belong to a user in this company.",
                required: true,
            ),
            new ToolProperty(
                name: 'subject',
                type: PropertyType::STRING,
                description: 'Subject line of the email. Short and specific.',
                required: true,
            ),
            new ToolProperty(
                name: 'body',
                type: PropertyType::STRING,
                description: 'The email body, written as the agent addressing the teammate. Markdown is supported '
                    . 'and rendered to HTML — do not add a signature or branding, the layout handles that.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $recipient_email, string $subject, string $body): array
    {
        if ($this->agent === null) {
            return ['status' => 'error', 'message' => 'No agent is in scope, so I cannot send an email.'];
        }

        $recipient_email = trim($recipient_email);
        $subject = trim($subject);
        $body = trim($body);

        if ($recipient_email === '' || $subject === '' || $body === '') {
            return ['status' => 'error', 'message' => 'A recipient email, a subject, and a body are all required.'];
        }

        try {
            $recipient = UsersRepository::getUserOfAppByEmail($recipient_email, $this->agent->app);
            UsersRepository::belongsToCompany($recipient, $this->agent->company);
        } catch (ModelNotFoundException | ExceptionsModelNotFoundException) {
            return [
                'status' => 'error',
                'message' => "No teammate in this company has the email {$recipient_email}. Ask the user to confirm who to email.",
            ];
        }

        try {
            $smtp = new SmtpRuntimeConfiguration($this->agent->app, $this->agent->company);
            $fromMail = $smtp->getFromEmail();

            Mail::send(
                new KanvasMailable($smtp->loadSmtpSettings(), MarkdownEmailRenderer::toEmailHtml($body))
                    ->from($fromMail['address'], $fromMail['name'])
                    ->to($recipient->email)
                    ->subject($subject),
            );
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'error', 'message' => 'The email could not be sent right now. Tell the user you will follow up.'];
        }

        $this->recordInLedger($recipient, $subject, $body);
        $this->recordInAgentChat($recipient, $subject, $body);

        return [
            'status' => 'success',
            'to' => $recipient->email,
            'subject' => $subject,
            'message' => "Email sent to {$recipient->email}.",
        ];
    }

    /**
     * The ledger is what read_my_ledger shows across every conversation of the agent. Delivery already
     * happened, so a ledger failure never fails the tool.
     */
    private function recordInLedger(Users $recipient, string $subject, string $body): void
    {
        try {
            new AppendEventAction(new EventData(
                app: $this->agent->app,
                company: $this->agent->company,
                sourceDomain: 'Intelligence',
                eventType: self::LEDGER_EVENT,
                status: EventStatusEnum::SUCCESS,
                sourceEntityType: Users::class,
                sourceEntityId: $recipient->getId(),
                actorType: 'Agent',
                actorId: $this->agent->getId(),
                payload: [
                    'to' => $recipient->email,
                    'subject' => $subject,
                    'body' => $body,
                ],
            ))->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Files the email in the recipient's own chat with this agent, so they see it there and the agent
     * has it in history when they answer. The in-app chat replays `agent_conversation_messages`, not the
     * Social feed, so it is written to both; the conversation is created when they never chatted yet.
     */
    private function recordInAgentChat(Users $recipient, string $subject, string $body): void
    {
        $agentUser = $this->agent->user;
        if ($agentUser === null) {
            return;
        }

        $content = 'Emailed you: **' . $subject . "**\n\n" . $body;

        try {
            $session = new UserAgentChannelService()->resolveSession(
                human: $recipient,
                agent: $this->agent,
                app: $this->agent->app,
                company: $this->agent->company,
                entity: $recipient,
            );

            new PostChannelMessageAction(
                channel: $session->channel,
                author: $agentUser,
                verb: self::CHAT_VERB,
                content: $content,
                extraPayload: ['from_ia' => true, 'agent_id' => $this->agent->getId()],
            )->execute();

            $store = new KanvasConversationStore();
            $store->conversationForSession(
                userId: $recipient->getId(),
                sessionId: $session->uuid,
                agentId: $this->agent->getId(),
                appsId: $this->agent->app->getId(),
                companiesId: $this->agent->company->getId(),
                participant: $recipient,
            );
            $store->appendAssistantMessageForSession(
                appsId: $this->agent->app->getId(),
                companiesId: $this->agent->company->getId(),
                sessionId: $session->uuid,
                agentClass: $this->agent->type?->handler ?? $this->agent::class,
                content: $content,
                agentId: $this->agent->getId(),
                userId: $recipient->getId(),
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
