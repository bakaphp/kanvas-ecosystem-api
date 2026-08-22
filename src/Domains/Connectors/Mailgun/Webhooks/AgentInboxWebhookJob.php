<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Webhooks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\Mailgun\Actions\AgentChannelResponderAction;
use Kanvas\Connectors\Mailgun\Actions\CreateMessageFromAgentInboxAction;
use Kanvas\Connectors\Mailgun\Actions\VerifyMailgunWebhookSignatureAction;
use Kanvas\Connectors\Mailgun\Enums\ReceiverConfigurationEnum;
use Kanvas\Connectors\Mailgun\Services\AgentInboxSenderResolverService;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Connectors\Mailgun\Services\MailgunPayloadService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Exceptions\AgentReplySkippedException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as SessionDto;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

/**
 * Inbound mail for one agent's own address — the email counterpart of ProcessSlackWebhookJob.
 *
 * Separate from AgentProcessEmailWebhookJob, which serves a company's shared lead inbox and picks
 * its agent from a workflow rule. Here the receiver *is* the agent's, so the agent is known before
 * the sender is, and an unknown sender can be turned away instead of becoming a lead.
 */
#[WorkflowAction(
    name: 'Mailgun Agent Inbox',
    description: 'Receiver for ONE agent\'s own email address: mail sent to that address reaches that agent, '
        . 'which reads it and replies by email. This CONTACTS the sender. The receiver belongs to the '
        . 'agent, so the agent is known before the sender is and an unrecognised sender is turned away '
        . 'rather than becoming a lead. Use the company lead inbox receiver instead for a shared '
        . 'address where the agent is chosen by a workflow rule.',
    integration: IntegrationsEnum::MAILGUN,
    params: [
        'agent_id' => 'REQUIRED. Id of the agent that owns this mailbox. Without it nothing is '
            . 'processed — every delivery answers "Receiver has no agent configured".',
        'mailbox_address' => 'The address the Mailgun route forwards here, e.g. sofia@mail.example.com.',
        'capture_files' => 'Stores the email\'s attachments and hangs them off the message, which is '
            . 'what lets a photo or PDF reach anything downstream. Already on for this receiver; set '
            . 'it to false only to discard attachments on purpose. There is no backfill — the files '
            . 'are unrecoverable once the delivery is over.',
    ],
)]
class AgentInboxWebhookJob extends ProcessWebhookJob
{
    private const int DEDUPE_TTL_SECONDS = 900;

    #[Override]
    public function execute(): array
    {
        $payload = new MailgunPayloadService($this->webhookRequest->payload);
        $sender = $payload->sender();

        if ($sender === '') {
            return ['message' => 'Email has no sender'];
        }

        $agentId = (int) ($this->receiver->configuration[ReceiverConfigurationEnum::AGENT_ID->value] ?? 0);

        if ($agentId === 0) {
            return ['message' => 'Receiver has no agent configured'];
        }

        /** @var Agent $agent */
        $agent = Agent::getById($agentId, $this->receiver->app);
        $mailbox = new AgentMailboxService()->addressFor($agent);

        // Our own reply comes back through the route when the agent is cc'd or a list echoes it.
        if ($mailbox !== null && $sender === $mailbox) {
            return ['message' => 'Message from the agent itself, ignored'];
        }

        if ($payload->isAutoReply()) {
            return ['message' => 'Auto-reply ignored'];
        }

        if (! $this->isFirstDelivery($payload->messageId())) {
            return ['message' => 'Duplicate delivery'];
        }

        if ($payload->text() === '') {
            return ['message' => 'Email has no readable body'];
        }

        $entity = new AgentInboxSenderResolverService($agent)->resolve($sender, $payload->senderName());

        if ($entity === null) {
            return ['message' => 'Sender is not known to this company and the mailbox is restricted'];
        }

        $message = new CreateMessageFromAgentInboxAction(
            $this->webhookRequest,
            $agent,
            $entity
        )->execute();

        /** @var Channel $channel */
        $channel = $message->channels()->firstOrFail();

        try {
            return new AgentChannelResponderAction(
                $channel,
                $message,
                $agent,
                $this->resolveSession(
                    $channel,
                    $message,
                    $agent,
                    $entity
                ),
            )->execute();
        } catch (AgentReplySkippedException | ValidationException $e) {
            // Expected control flow — AI switched off for this lead, an already-answered message, an
            // empty turn. Reporting it would bury the real faults in the same feed.
            return ['message' => $e->getMessage()];
        }
    }

    #[Override]
    public static function capturesFiles(): bool
    {
        return true;
    }

    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        return new VerifyMailgunWebhookSignatureAction(
            $receiver->app,
            $receiver->company,
            (string) $request->input('token', ''),
            (string) $request->input('timestamp', ''),
            (string) $request->input('signature', ''),
        )->execute();
    }

    private function resolveSession(Channel $channel, Message $message, Agent $agent, Model $entity): Session
    {
        return new CreateSessionAction(
            SessionDto::from([
                'app' => $this->receiver->app,
                'company' => $this->receiver->company,
                'channel' => $channel,
                'agent' => $agent,
                'entity_namespace' => $entity::class,
                'entity_id' => $entity->getId(),
                'canal_id' => (string) ($message->message['chat_jid'] ?? ''),
                'user' => $this->sessionUser($entity, (string) ($message->message['from_email'] ?? '')),
            ]),
        )->execute();
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionUser(Model $entity, string $email): array
    {
        $person = $entity instanceof Lead ? $entity->people : $entity;

        $name = match (true) {
            $person instanceof Users => trim($person->firstname . ' ' . $person->lastname),
            $person instanceof People => (string) $person->getName(),
            default => $email,
        };

        return [
            'id' => $person?->getId(),
            'name' => $name !== '' ? $name : $email,
            'email' => $email,
        ];
    }

    /**
     * Mailgun retries a forward that doesn't answer 200 quickly, and a mail client that cc's the
     * address on a thread it also replies-all to delivers the same Message-Id twice. Either way the
     * agent must not answer the same email twice.
     */
    private function isFirstDelivery(string $messageId): bool
    {
        if ($messageId === '') {
            return true;
        }

        return Cache::add('mailgun:agent-inbox:' . md5($messageId), true, self::DEDUPE_TTL_SECONDS);
    }
}
