<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Services;

use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

/**
 * The single announcement point for an approval lifecycle event: it records the decision in the
 * NervousSystem ledger and fires the workflow rule engine.
 *
 * Both, because they answer different questions. The ledger is the durable audit — who signed, who
 * else was asked, who declined — and it is the only place that answers it: the domain's own events
 * (`scribe.bill.received`) record the resulting state change, not the decision that produced it, and
 * a non-Scribe approvable entity emits nothing at all. The rule engine is the tenant's extension point.
 *
 * The workflow fires on two surfaces.
 *
 * The ApprovalRequest row is the PERMANENT lane: one rule on this system module catches every
 * approval regardless of entity type, and per-type targeting comes from a rule *condition*
 * (`approval_type == 'approve_bill'`) rather than from a separate system module. `target` is passed
 * in params so those conditions can also reach the approved record's own fields.
 *
 * The target entity fire is ROLLOUT COMPATIBILITY ONLY. It exists so rules a tenant already attached
 * to a Bill/Expense keep firing during adoption, and it is removed once no tenant rules remain on
 * entity system modules for these events — check with `kanvas:approvals:list-entity-fired-rules`.
 * Do not build new behaviour on it.
 */
class ApprovalWorkflowService
{
    /**
     * @param array<string, mixed> $extra
     */
    public function fire(ApprovalRequest $request, WorkflowEnum $event, array $extra = []): void
    {
        $this->record($request, $event);

        $entity = $request->resolveEntity();

        $params = [
            'app' => $request->app,
            'company' => $request->company,
            'approval' => $request,
            'target' => $entity,
            'payload' => $request->payload,
            ...$extra,
        ];

        if ($entity !== null && method_exists($entity, 'fireWorkflow')) {
            $entity->fireWorkflow($event->value, params: $params);
        }

        $request->fireWorkflow($event->value, params: $params);
    }

    /**
     * Best-effort: a ledger write must never roll back the decision it is describing. A missing audit
     * line is recoverable from the request row itself; a lost approval is not.
     */
    public function record(ApprovalRequest $request, WorkflowEnum $event): void
    {
        try {
            $request->emitLedgerEvent(
                eventType: 'approvals.' . str_replace('-', '_', $event->value),
                status: $this->statusFor($event),
                payload: $request->ledgerPayload(),
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * A rejection or an expiry is a normal outcome, not a fault — ERROR is reserved for the ledger's
     * own failures so a dashboard filtering on it does not fill up with people declining bills.
     */
    private function statusFor(WorkflowEnum $event): EventStatusEnum
    {
        return match ($event) {
            WorkflowEnum::APPROVED => EventStatusEnum::SUCCESS,
            default => EventStatusEnum::INFO,
        };
    }
}
