<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Messages;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Social\Messages\Actions\ApproveAgentMessageAction;
use Kanvas\Social\Messages\Actions\RejectAgentMessageAction;
use Kanvas\Social\Messages\Models\Message;

/**
 * The message-shaped door onto the approvals domain, kept because the frontend's approval card is
 * built against it and it carries an edited draft, which the generic approveApprovalRequest has no
 * place for. Whether the caller may decide is settled by their approver row inside ApproveAction —
 * seeing the channel is not the same as being asked to sign off on it.
 */
class MessageApprovalMutation
{
    use ResolvesActingContext;

    public function approve(mixed $root, array $request): Message
    {
        $ctx = $this->actingContext();

        /** @var Message $message */
        $message = Message::getByIdFromCompanyApp((int) $request['id'], $ctx->company, $ctx->app);

        return new ApproveAgentMessageAction(
            $message,
            $request['message'] ?? null,
            // The approver's input — e.g. { project_id } when they confirm/redirect a routing approval.
            // Merged over the request's stored context, so it's optional for approvals that need none.
            (array) ($request['context'] ?? []),
            $ctx->user,
        )->execute();
    }

    public function reject(mixed $root, array $request): bool
    {
        $ctx = $this->actingContext();

        /** @var Message $message */
        $message = Message::getByIdFromCompanyApp((int) $request['id'], $ctx->company, $ctx->app);

        return new RejectAgentMessageAction(
            $message,
            $request['reason'] ?? null,
            $ctx->user,
        )->execute();
    }
}
