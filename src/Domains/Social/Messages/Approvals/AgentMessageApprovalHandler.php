<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Approvals;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Approvals\Contracts\ApprovalHandlerInterface;
use Kanvas\Approvals\Contracts\ApprovalRejectionHandlerInterface;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Connectors\Mailgun\Actions\SendAgentEmailAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Enums\ChannelCategoryEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Support\MessageApproval;
use Override;

/**
 * What approving a held agent draft DOES — the one handler behind every message approval.
 *
 * The approvals domain binds a handler per policy, but a message names its own action per instance, so
 * this dispatches to the card's `approval.handler` and falls back to shipping the draft down its own
 * channel. That indirection is what lets a new approval kind ship a handler class and nothing else —
 * no policy edit, no arm added to a match here.
 */
class AgentMessageApprovalHandler implements ApprovalHandlerInterface, ApprovalRejectionHandlerInterface
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function handle(ApprovalRequest $request, ?UserInterface $approver): array
    {
        $message = $this->resolveMessage($request);
        $handlerClass = MessageApproval::handler($message);

        if ($handlerClass !== null) {
            new $handlerClass()->approve($message, $this->mergedContext($message, $request));
        } else {
            $this->sendByVerb($message);
        }

        // The card renders off approval.status, not the lock, so unlocking alone leaves an approved
        // draft still offering a live Approve button for work that is already done.
        MessageApproval::settle($message, MessageApproval::STATUS_APPROVED);
        $message->setUnlock();

        return [
            'message_id' => $message->getId(),
            'kind' => MessageApproval::kind($message),
        ];
    }

    /**
     * Discarded, not merely un-held: a draft left recoverable in the feed invites being sent later by
     * a path that only checks the lock. The approval_requests row keeps what was refused and why.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function reject(ApprovalRequest $request, ?UserInterface $approver, ?string $reason): array
    {
        $message = $this->resolveMessage($request);

        MessageApproval::settle($message, MessageApproval::STATUS_REJECTED);
        $message->softDelete();

        return [
            'message_id' => $message->getId(),
            'discarded' => true,
        ];
    }

    private function resolveMessage(ApprovalRequest $request): Message
    {
        $message = $request->resolveEntity();

        if (! $message instanceof Message) {
            throw new ValidationException(
                "Approval request {$request->getId()} does not point at a message.",
            );
        }

        return $message;
    }

    /**
     * The requester's stored context, overlaid with what the approver supplied — the approver wins,
     * because redirecting the action is the whole point of letting them send anything at all (a
     * routing card suggests a project; the human picks a different one).
     *
     * @return array<string, mixed>
     */
    private function mergedContext(Message $message, ApprovalRequest $request): array
    {
        return array_merge(MessageApproval::context($message), $request->decisionContext());
    }

    /**
     * No handler on the card → an outbound the agent already composed, and approving means shipping
     * it. Mailgun is the only verb wired so far.
     */
    private function sendByVerb(Message $message): void
    {
        $verb = $message->messageType?->verb;

        match ($verb) {
            ChannelCategoryEnum::MAILGUN->value => new SendAgentEmailAction($message)->execute(),
            default => throw new ValidationException(
                'Approval send is not supported for message type: ' . (string) $verb,
            ),
        };
    }
}
