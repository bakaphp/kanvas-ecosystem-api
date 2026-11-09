<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Accounting\Concerns;

use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ApprovePendingItemTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Approvals\CheckApprovalStatusTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Approvals\RetryApprovedItemTool;

/**
 * The approval tools the AP and AR agents share, each bound to the right identity.
 *
 * check_approval_status and retry_approved_item are read-only or re-run a decision already taken, so
 * unlike approve_pending_item they are not gated on a requesting human: reporting where something stands
 * authorizes nothing. approve_pending_item must authorize against the real human, never actingUser(),
 * which is the agent itself on the @mention and channel surfaces.
 */
trait GrantsAccountingApprovalTools
{
    /**
     * @return list<object>
     */
    protected function accountingApprovalTools(): array
    {
        if ($this->app === null || $this->company === null) {
            return [];
        }

        $tools = [];

        $statusUser = $this->requestingHuman() ?? $this->actingUser();
        if ($statusUser !== null) {
            $tools[] = new CheckApprovalStatusTool()->withContext($this->app, $this->company, $statusUser);
            $tools[] = new RetryApprovedItemTool()->withContext($this->app, $this->company, $statusUser);
        }

        $requestingHuman = $this->requestingHuman();
        if ($requestingHuman !== null) {
            $tools[] = new ApprovePendingItemTool()->withContext($this->app, $this->company, $requestingHuman);
        }

        return $tools;
    }
}
