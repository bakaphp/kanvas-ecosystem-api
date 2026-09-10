<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Approvals\Actions\ApproveAction;
use Kanvas\Approvals\DataTransferObject\ApprovalResult;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Messages\Models\Message;

/**
 * Approves a held agent draft.
 *
 * The decision itself belongs to the approvals domain — approver rows, quorum, the single-claim that
 * stops two reviewers running the action twice, the ledger entry, the workflow fire. This is the
 * message-shaped door onto it: it applies the reviewer's edit to the draft (a message concern, and it
 * has to land before the handler reads the content it is about to send) and hands the rest to
 * ApproveAction. What approving actually DOES lives in AgentMessageApprovalHandler.
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
        protected ?UserInterface $approver = null,
    ) {
    }

    public function execute(): Message
    {
        $request = $this->message->pendingApproval();

        if ($request === null) {
            throw new ValidationException('Message is not pending approval');
        }

        if ($this->editedText !== null && $this->editedText !== '') {
            $this->message->addMessage([
                'content' => $this->editedText,
                'raw_data' => $this->editedText,
            ]);
        }

        $result = new ApproveAction(
            request: $request,
            approver: $this->approver ?? auth()->user(),
            context: $this->context,
        )->execute();

        $this->assertHandlerLanded($result);

        return $this->message->refresh();
    }

    /**
     * The approvals domain records the decision even when the downstream action fails — right for a
     * bill, where "approved but the ERP push errored" are two separate facts. A message draft has no
     * such split: the reviewer pressed Approve to send it, so a send that did not happen has to come
     * back as an error rather than as a message that quietly reads approved.
     */
    private function assertHandlerLanded(ApprovalResult $result): void
    {
        $error = $result->handlerError();

        if ($error !== null) {
            throw new ValidationException($error);
        }
    }
}
