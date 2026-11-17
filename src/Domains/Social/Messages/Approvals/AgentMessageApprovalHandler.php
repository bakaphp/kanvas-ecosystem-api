<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Approvals;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Approvals\Contracts\ApprovalHandlerInterface;
use Kanvas\Approvals\Contracts\ApprovalRejectionHandlerInterface;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Communication\Messages\Actions\DeliverAgentReplyAction;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Support\MessageApproval;
use Override;

/**
 * What approving a held agent draft DOES — the one handler behind every message approval.
 *
 * The approvals domain binds a handler per policy, but a message names its own action per instance, so
 * this dispatches to the card's `approval.handler` and falls back to sending the draft in the
 * conversation it answers. That indirection is what lets a new approval kind ship a handler class and nothing else —
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
        $delivery = null;

        if ($handlerClass !== null) {
            new $handlerClass()->approve($message, $this->mergedContext($message, $request));
        } else {
            $delivery = $this->sendHeldReply($message);
        }

        // Settled before anything else can throw: a failure past this point is recorded as a failed
        // approval, and a card left pending would offer the same send again. The card renders off
        // approval.status, not the lock, so unlocking alone leaves a live Approve button.
        MessageApproval::settle($message, MessageApproval::STATUS_APPROVED);
        $message->setUnlock();

        return [
            'message_id' => $message->getId(),
            'kind' => MessageApproval::kind($message),
            ...($delivery !== null ? ['delivery_status' => $delivery->status->value] : []),
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
     * No handler on the card → a reply the agent already wrote, and approving means sending it in
     * the conversation it answers, whatever the medium.
     *
     * Throwing makes the approval retryable, and a retry sends. So only a send that certainly did
     * not leave throws: one whose outcome was lost may be with the provider, and is settled as it
     * stands for the delivery ledger to resolve. For the same reason a draft that already has such
     * a delivery is not sent a second time.
     */
    private function sendHeldReply(Message $message): Delivery
    {
        $delivery = $this->deliveryThatMayHaveLeft($message)
            ?? DeliverAgentReplyAction::forHeldReply($message)->execute();

        if ($delivery === null || ! $delivery->status->mayHaveReachedTheProvider()) {
            throw new ValidationException(
                'The approved message was not sent: '
                . ($delivery?->error ?? $delivery?->error_code ?? 'no connection')
            );
        }

        return $delivery;
    }

    private function deliveryThatMayHaveLeft(Message $message): ?Delivery
    {
        return Delivery::query()
            ->fromApp($message->app)
            ->fromCompany((int) $message->companies_id)
            ->where('message_id', $message->getId())
            ->orderByDesc('id')
            ->get()
            ->first(fn (Delivery $delivery): bool => $delivery->status->mayHaveReachedTheProvider());
    }
}
