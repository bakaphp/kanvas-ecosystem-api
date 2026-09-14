<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Approvals\DataTransferObject\ApprovalStep;
use Kanvas\Approvals\Enums\ApprovalDecisionEnum;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Services\ApprovalConditionEvaluatorService;
use Kanvas\Approvals\Services\ApprovalWorkflowService;
use Kanvas\Approvals\Services\ApproverResolverRegistryService;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

/**
 * Opens a request and writes its whole approval chain up front.
 *
 * Materializing every step at request time — including the ones whose `when` fails, as SKIPPED — is
 * what makes the audit answerable later: approver lists change, so a record saying "approved by X"
 * has to also say who else was asked on that date, and who was deliberately not.
 */
class RequestApprovalAction
{
    public function __construct(
        protected readonly Model $entity,
        protected readonly ApprovalPolicy $policy,
        protected readonly ApprovalOriginEnum $origin = ApprovalOriginEnum::SYSTEM,
        protected readonly ?UserInterface $requestedBy = null,
        protected readonly array $payload = [],
        protected readonly ApproverResolverRegistryService $registry = new ApproverResolverRegistryService(),
        protected readonly ApprovalConditionEvaluatorService $evaluator = new ApprovalConditionEvaluatorService(),
        protected readonly ApprovalWorkflowService $workflow = new ApprovalWorkflowService(),
    ) {
    }

    public function execute(): ApprovalRequest
    {
        $request = $this->createRequest();

        // Outside the transaction: neither a notification nor a workflow rule may roll back the
        // request that triggered them. An unassigned request gets its own event so a tenant can route
        // it somewhere rather than have it sit unnoticed.
        new NotifyApproversAction($request)->execute();

        $this->workflow->fire(
            $request,
            $request->isUnassigned() ? WorkflowEnum::APPROVAL_UNASSIGNED : WorkflowEnum::APPROVAL_REQUESTED,
            ['requestedBy' => $this->requestedBy]
        );

        return $request;
    }

    private function createRequest(): ApprovalRequest
    {
        return DB::transaction(function (): ApprovalRequest {
            $request = ApprovalRequest::create([
                'apps_id' => $this->entity->apps_id,
                'companies_id' => $this->entity->companies_id,
                'system_modules_id' => $this->entity->approvalSystemModuleId(),
                'entity_id' => (int) $this->entity->getKey(),
                'approval_type' => $this->policy->approval_type,
                'approval_policies_id' => $this->policy->getId(),
                'origin' => $this->origin,
                'requested_by_users_id' => $this->requestedBy?->getId(),
                'payload' => $this->payload,
                'status' => ApprovalStatusEnum::PENDING,
                'current_step' => 0,
                'expires_at' => $this->expiresAt(),
            ]);

            $this->materializeChain($request);

            return $request;
        });
    }

    private function materializeChain(ApprovalRequest $request): void
    {
        $data = $this->conditionData();
        $firstApplicableStep = null;
        $anyoneToAsk = false;

        foreach ($this->policy->approvalSteps() as $step) {
            if (! $this->evaluator->matches($step->when, $data)) {
                $this->writeApprovers(
                    $request,
                    $step,
                    $this->resolveApprovers($step, useFallback: false),
                    ApprovalDecisionEnum::SKIPPED
                );

                continue;
            }

            $approvers = $this->resolveApprovers($step, useFallback: true);

            // The FIRST applicable step is the live one, whether or not it resolved anybody. Promoting
            // a later step because this one found no approvers would silently drop a required
            // signature — a misconfigured step has to stick as unassigned, not quietly disappear.
            $isFirstApplicable = $firstApplicableStep === null;
            $firstApplicableStep ??= $step->step;
            $anyoneToAsk = $anyoneToAsk || ($isFirstApplicable && $approvers->isNotEmpty());

            $this->writeApprovers(
                $request,
                $step,
                $approvers,
                $isFirstApplicable ? ApprovalDecisionEnum::PENDING : ApprovalDecisionEnum::WAITING
            );
        }

        $request->current_step = $firstApplicableStep ?? 0;
        $request->metadata = [
            ...($request->metadata ?? []),
            // Two different failures, and B3 treats them differently: nothing to ask (every step's
            // condition failed) can complete on its own; nobody to ask needs a human to look at it.
            'no_live_steps' => $firstApplicableStep === null,
            'unassigned' => $firstApplicableStep !== null && ! $anyoneToAsk,
        ];

        $request->save();
    }

    /**
     * A step whose condition failed still gets its approvers listed, so the audit can show the CFO was
     * not asked and why — but it never consumes the fallback resolver, which exists to rescue a step
     * that actually applies.
     *
     * @return Collection<int, Users>
     */
    private function resolveApprovers(ApprovalStep $step, bool $useFallback): Collection
    {
        $approvers = $this->runResolver($step->resolver, $step->config);

        if ($approvers->isNotEmpty() || ! $useFallback || $this->policy->fallback_resolver === null) {
            return $approvers;
        }

        return $this->runResolver($this->policy->fallback_resolver, (array) ($this->policy->fallback_config ?? []));
    }

    /**
     * @return Collection<int, Users>
     */
    private function runResolver(string $key, array $config): Collection
    {
        $resolver = $this->registry->get($key);

        if ($resolver === null) {
            return collect();
        }

        try {
            return $resolver->resolve($this->entity, $config);
        } catch (Throwable) {
            // A resolver that blows up is a misconfigured policy. That has to surface as a visibly
            // unassigned request, never as an exception thrown back into whatever was being created.
            return collect();
        }
    }

    private function writeApprovers(
        ApprovalRequest $request,
        ApprovalStep $step,
        Collection $approvers,
        ApprovalDecisionEnum $decision
    ): void {
        foreach ($approvers as $approver) {
            $request->approvers()->create([
                'users_id' => $approver->getId(),
                'email' => $approver->email,
                'step' => $step->step,
                'decision' => $decision,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function conditionData(): array
    {
        return [
            ...$this->entity->toArray(),
            'origin' => $this->origin->value,
            'payload' => $this->payload,
        ];
    }

    private function expiresAt(): ?Carbon
    {
        $hours = $this->policy->expires_after_hours;

        return $hours !== null && $hours > 0 ? Carbon::now()->addHours($hours) : null;
    }
}
