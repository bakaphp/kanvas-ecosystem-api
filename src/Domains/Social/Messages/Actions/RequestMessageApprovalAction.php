<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Contracts\AgentApprovalHandler;
use Kanvas\Social\Messages\Approvals\MessageApprovalPolicyService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Support\MessageApproval;

/**
 * The one way a message becomes a pending approval — an agent asking to run an action, or an outbound
 * reply held because the company is in approval mode.
 *
 * Nothing else may call setLock() to gate a message: the lock is derived state, written here and
 * cleared only by the decision, and a second writer is how the card and the record drift apart.
 */
class RequestMessageApprovalAction
{
    /**
     * @param string $kind the discriminator the frontend card switches its UI on — take it from a
     *                      handler's KIND constant, never inline
     * @param class-string<AgentApprovalHandler>|null $handler what approving runs; null ships the
     *                      draft down its own channel instead
     * @param array<string, mixed> $context what the handler needs, rendered by the card
     * @param bool $private hidden from public feeds, or left visible in the reviewer's own feed
     */
    public function __construct(
        private readonly Message $message,
        private readonly string $kind,
        private readonly ?string $handler = null,
        private readonly array $context = [],
        private readonly bool $private = true,
    ) {
    }

    public function execute(): ApprovalRequest
    {
        MessageApproval::wrap(
            $this->message,
            $this->kind,
            $this->handler,
            $this->context,
            $this->private
        );

        MessageApprovalPolicyService::ensureFor($this->message);

        $request = $this->message->requestApproval(
            MessageApproval::APPROVAL_TYPE,
            // Off the card, not rebuilt from the same constructor args: policy steps condition on
            // `payload.kind`, so it has to be the kind the reviewer is actually looking at.
            payload: MessageApproval::requestPayload($this->message),
            // The message's own author, not whoever happens to be authenticated: these are opened from
            // queue workers and workflow activities where there is no request user at all.
            requestedBy: $this->message->user,
            origin: ApprovalOriginEnum::AGENT,
        );

        // ensureFor() just guaranteed a policy, so null means the row was deleted underneath us. Loud,
        // because the alternative is a locked draft with no record that can ever approve it.
        return $request ?? throw new ValidationException(
            'No approval policy for message ' . $this->message->getId() . '; the draft cannot be approved.',
        );
    }
}
