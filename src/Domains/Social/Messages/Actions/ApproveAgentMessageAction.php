<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Kanvas\Connectors\Mailgun\Actions\SendAgentEmailAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Contracts\AgentApprovalHandler;
use Kanvas\Social\Enums\ChannelCategoryEnum;
use Kanvas\Social\Messages\Models\Message;

/**
 * Approves a locked agent draft, runs the approved action, and unlocks it.
 *
 * A message can name its own approval handler (RequestAgentApprovalAction stores it under
 * `message.approval.handler`) — that's how any agent action gets human sign-off (orchestrator routing,
 * …). When no handler is set we fall back to routing by the outbound message-type verb (the original
 * email path, so existing locked email drafts keep working).
 */
class ApproveAgentMessageAction
{
    /**
     * @param array<string, mixed> $context the approver's input, merged over the request's stored
     *                                       context (approver wins — e.g. redirecting to another project)
     */
    public function __construct(
        protected Message $message,
        protected ?string $editedText = null,
        protected array $context = [],
    ) {
    }

    public function execute(): Message
    {
        if (! $this->message->isLocked()) {
            throw new ValidationException('Message is not pending approval');
        }

        if ($this->editedText !== null && $this->editedText !== '') {
            $this->message->addMessage([
                'content' => $this->editedText,
                'raw_data' => $this->editedText,
            ]);
        }

        $handler = $this->resolveHandler();
        if ($handler !== null) {
            $handler->approve($this->message, $this->mergedContext());
        } else {
            $this->sendByVerb();
        }

        $this->message->setUnlock();

        return $this->message->refresh();
    }

    private function resolveHandler(): ?AgentApprovalHandler
    {
        $payload = $this->message->message;
        $class = is_array($payload) ? ($payload['approval']['handler'] ?? null) : null;

        if (! is_string($class) || $class === '') {
            return null;
        }

        if (! class_exists($class) || ! is_subclass_of($class, AgentApprovalHandler::class)) {
            throw new ValidationException("Invalid approval handler: {$class}");
        }

        return new $class();
    }

    /**
     * @return array<string, mixed>
     */
    private function mergedContext(): array
    {
        $payload = $this->message->message;
        $stored = is_array($payload) ? (array) ($payload['approval']['context'] ?? []) : [];

        return array_merge($stored, $this->context);
    }

    /**
     * Legacy path: no handler on the message → ship it via its channel by message-type verb. Only
     * Mailgun is wired here (the original agent-email approval).
     */
    private function sendByVerb(): void
    {
        $verb = $this->message->messageType?->verb;

        match ($verb) {
            ChannelCategoryEnum::MAILGUN->value => new SendAgentEmailAction($this->message)->execute(),
            default => throw new ValidationException(
                'Approval send is not supported for message type: ' . (string) $verb,
            ),
        };
    }
}
