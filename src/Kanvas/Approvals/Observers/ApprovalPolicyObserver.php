<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Observers;

use Kanvas\Approvals\Models\ApprovalPolicy;
use Throwable;

/**
 * Every write to a policy is audited, whoever made it — the GraphQL mutations, a seed command, a setup
 * service. A policy decides who has to sign before money moves or an e-CF reaches the tax authority, so
 * "who switched this off, and when" has to be answerable afterwards; the row itself keeps no history.
 *
 * `created` and `updated` rather than `saved` + `wasRecentlyCreated`: that flag stays true on the instance,
 * so every later save of a freshly created policy would be filed as another creation. Kanvas soft-delete
 * fires `updated` (it goes through saveOrFail()), which is where a delete is recognised. In `updated` the
 * model's original values are still the pre-write ones.
 */
class ApprovalPolicyObserver
{
    private const array IGNORED = ['updated_at', 'created_at'];

    public function created(ApprovalPolicy $policy): void
    {
        $this->record($policy, ['change' => 'created', 'before' => null, 'after' => $policy->auditedAttributes()]);
    }

    public function updated(ApprovalPolicy $policy): void
    {
        $change = $this->changeOf($policy);

        if ($change !== null) {
            $this->record($policy, $change);
        }
    }

    /**
     * @param array{change: string, before: array<string, mixed>|null, after: array<string, mixed>|null} $change
     */
    private function record(ApprovalPolicy $policy, array $change): void
    {
        // Best-effort: an audit write must never roll back the policy change it describes.
        try {
            $policy->emitLedgerEvent(
                eventType: 'approvals.policy_changed',
                payload: [
                    'change' => $change['change'],
                    'approval_policy_id' => $policy->getId(),
                    'approval_type' => $policy->approval_type,
                    'before' => $change['before'],
                    'after' => $change['after'],
                ],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array{change: string, before: array<string, mixed>|null, after: array<string, mixed>|null}|null
     */
    private function changeOf(ApprovalPolicy $policy): ?array
    {
        $changed = array_diff(array_keys($policy->getChanges()), self::IGNORED);

        if ($changed === []) {
            return null;
        }

        if (in_array('is_deleted', $changed, true) && $policy->is_deleted) {
            return ['change' => 'deleted', 'before' => $policy->auditedAttributes(original: true), 'after' => null];
        }

        $before = [];
        $after = [];

        foreach ($changed as $key) {
            $before[$key] = $policy->getOriginal($key);
            $after[$key] = $policy->getAttribute($key);
        }

        return ['change' => 'updated', 'before' => $before, 'after' => $after];
    }
}
