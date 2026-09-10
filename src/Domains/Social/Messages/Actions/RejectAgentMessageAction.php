<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Approvals\Actions\RejectAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Messages\Models\Message;

/**
 * Rejects a held agent draft — the reply is discarded and never sent.
 *
 * The discard is the handler's job (AgentMessageApprovalHandler::reject), not this action's: a
 * rejection has to be recorded before anything acts on it, and the approvals domain is what records
 * who said no and why.
 */
class RejectAgentMessageAction
{
    public function __construct(
        protected Message $message,
        protected ?string $reason = null,
        protected ?UserInterface $approver = null,
    ) {
    }

    public function execute(): bool
    {
        $request = $this->message->pendingApproval();

        if ($request === null) {
            throw new ValidationException('Message is not pending approval');
        }

        $result = new RejectAction(
            $request,
            $this->approver ?? auth()->user(),
            $this->reason,
        )->execute();

        // A failed discard would otherwise come back as a bare `false` — a draft that reads rejected
        // in the audit while still sitting in the feed, reachable by anything that only checks the lock.
        $error = $result->handlerError();

        if ($error !== null) {
            throw new ValidationException($error);
        }

        return (bool) $this->message->refresh()->is_deleted;
    }
}
