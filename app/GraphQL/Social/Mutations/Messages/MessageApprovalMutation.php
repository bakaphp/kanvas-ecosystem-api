<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Messages;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Actions\ApproveAgentMessageAction;
use Kanvas\Social\Messages\Actions\RejectAgentMessageAction;
use Kanvas\Social\Messages\Models\Message;

class MessageApprovalMutation
{
    public function approve(mixed $root, array $request): Message
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var Message $message */
        $message = Message::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new ApproveAgentMessageAction(
            $message,
            $request['message'] ?? null,
            // The approver's input — e.g. { project_id } when they confirm/redirect a routing approval.
            // Merged over the request's stored context, so it's optional for approvals that need none.
            (array) ($request['context'] ?? []),
        )->execute();
    }

    public function reject(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var Message $message */
        $message = Message::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new RejectAgentMessageAction($message)->execute();
    }
}
