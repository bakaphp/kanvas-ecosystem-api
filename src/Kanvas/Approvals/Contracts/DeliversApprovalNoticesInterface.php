<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Contracts;

use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Users\Models\Users;

/**
 * Implemented by an approvable entity that can reach a person somewhere better than email — a Slack DM
 * from the agent that owns the work, with the source PDF attached.
 *
 * The approvals domain asks the entity first and falls back to email when it answers false, so every
 * opener (an agent tool, the inbox, GraphQL, an import) notifies the same way without knowing which
 * channel was used. The entity reads `$request->status` to tell a new request from an expired one.
 */
interface DeliversApprovalNoticesInterface
{
    /**
     * @return bool true when the notice reached the recipient; false sends the email instead
     */
    public function deliverApprovalNotice(ApprovalRequest $request, Users $recipient): bool;
}
