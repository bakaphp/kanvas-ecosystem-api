<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Repositories;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Apps\Models\Apps;

class ApprovalPolicyRepository
{
    /**
     * The policy governing this entity, or null when the tenant has not configured one — which is the
     * normal answer for the overwhelming majority of models and must stay cheap and silent.
     *
     * Deliberately NOT memoized in a static. The lifecycle hooks call this on every save, so caching
     * is tempting, but Octane and queue workers reuse the process: a static would keep serving a
     * policy that has since been edited or deleted, silently gating (or failing to gate) records for
     * as long as the worker lives. This is one lookup on ap_policy_lookup_idx; if it ever shows up in
     * a profile, cache it in Redis with invalidation on policy write, not in process memory.
     *
     * A company-specific policy beats an app-wide one (companies_id 0) for the same system module and
     * approval type; ordering by companies_id descending is what expresses that.
     */
    public static function findForEntity(
        Model $entity,
        ApprovalTriggerEnum $trigger,
        ?string $approvalType = null
    ): ?ApprovalPolicy {
        $company = $entity->company ?? null;

        if ($company === null) {
            return null;
        }

        $app = $entity->app ?? app(Apps::class);
        $systemModuleId = $entity->approvalSystemModuleId();

        /** @var ApprovalPolicy|null $policy */
        $policy = ApprovalPolicy::query()
            ->where('apps_id', $app->getId())
            ->whereIn('companies_id', [$company->getId(), 0])
            ->where('system_modules_id', $systemModuleId)
            ->where('trigger', $trigger->value)
            ->when($approvalType !== null, fn ($query) => $query->where('approval_type', $approvalType))
            ->notDeleted()
            ->orderByDesc('companies_id')
            ->orderBy('id')
            ->first();

        return $policy;
    }

    public static function findByType(Model $entity, string $approvalType): ?ApprovalPolicy
    {
        return self::findForEntity($entity, ApprovalTriggerEnum::MANUAL, $approvalType);
    }
}
