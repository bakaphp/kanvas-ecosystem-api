<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Services;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Approvals\Actions\CancelApprovalAction;
use Kanvas\Approvals\Actions\RequestApprovalAction;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Repositories\ApprovalPolicyRepository;
use Throwable;

/**
 * Turns a model's own lifecycle into approval requests, so no intake path has to remember to ask.
 *
 * Every method here is best-effort by design: a misconfigured policy, a missing system module, a
 * resolver that throws — none of those may take down the create/update that triggered them. A failed
 * gate is a visible missing approval, which is recoverable; a failed create is lost work.
 */
class ApprovalTriggerService
{
    public static function onCreated(Model $entity): void
    {
        self::maybeRequest($entity, ApprovalTriggerEnum::ON_CREATE);
    }

    public static function onUpdated(Model $entity): void
    {
        // Kanvas soft-delete is an UPDATE that also fires `updated`, so without this a record being
        // deleted would open an approval on its way out.
        if ((bool) ($entity->is_deleted ?? false)) {
            return;
        }

        self::maybeRequest($entity, ApprovalTriggerEnum::ON_UPDATE);
    }

    /**
     * A deleted entity leaves its open request pending forever: it shows in myPendingApprovals, the
     * expiry sweep keeps touching it, and approvers get asked about a record that no longer exists.
     */
    public static function onDeleted(Model $entity): void
    {
        try {
            $pending = $entity->pendingApproval();

            if ($pending !== null) {
                new CancelApprovalAction($pending, 'The record under approval was deleted.')->execute();
            }
        } catch (Throwable) {
            return;
        }
    }

    private static function maybeRequest(Model $entity, ApprovalTriggerEnum $trigger): void
    {
        try {
            $policy = ApprovalPolicyRepository::findForEntity($entity, $trigger);

            if ($policy === null) {
                return;
            }

            $origin = ApprovalOriginService::current();

            $condition = $policy->trigger_condition;
            $data = [...$entity->toArray(), 'origin' => $origin->value];

            if (! new ApprovalConditionEvaluatorService()->matches($condition, $data)) {
                return;
            }

            // A re-save must not open a second request for the same entity.
            if ($entity->pendingApproval() !== null) {
                return;
            }

            new RequestApprovalAction(
                entity: $entity,
                policy: $policy,
                origin: $origin,
            )->execute();
        } catch (Throwable) {
            return;
        }
    }
}
