<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Traits;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Approvals\Actions\ApproveAction;
use Kanvas\Approvals\Actions\RejectAction;
use Kanvas\Approvals\Actions\RequestApprovalAction;
use Kanvas\Approvals\Actions\SystemRejectAction;
use Kanvas\Approvals\DataTransferObject\ApprovalResult;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Exceptions\ApprovalRequiredException;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Repositories\ApprovalPolicyRepository;
use Kanvas\Approvals\Services\ApprovalTriggerService;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;

/**
 * Makes any model approvable. The model side is only this trait plus a system_modules row — what
 * "approving this" actually means is an approval_policies row, not code.
 */
trait HasApprovals
{
    /**
     * Resolved system module ids, keyed by "model|appId". pendingApproval() runs on every save of
     * every approvable model, and without this each call is another system_modules lookup.
     *
     * @var array<string, int>
     */
    private static array $approvalSystemModuleIds = [];

    /**
     * Static event closures delegating to a stateless service — NOT static::observe(), which calls
     * `new static` while the trait is booting and fatals with "bootIfNotBooted may not be called on
     * model [X] while it is being booted". See root CLAUDE.md.
     *
     * Both delete events are registered: Laravel's `deleted` fires on a real delete, while Kanvas
     * soft-delete goes through KanvasModelTrait::softDelete() which fires its own `softDeleted`.
     */
    public static function bootHasApprovals(): void
    {
        if (! static::approvalUsesLifecycleTriggers()) {
            return;
        }

        static::created(fn (Model $model) => ApprovalTriggerService::onCreated($model));
        static::updated(fn (Model $model) => ApprovalTriggerService::onUpdated($model));
        static::deleted(fn (Model $model) => ApprovalTriggerService::onDeleted($model));
        static::registerModelEvent(
            'softDeleted',
            fn (Model $model) => ApprovalTriggerService::onDeleted($model)
        );
    }

    /**
     * Whether saving this model should itself look for a policy to open a request from.
     *
     * On for approvable business records, where the whole point is that no intake path has to
     * remember to ask. Off for a model that is written constantly and only ever gated explicitly —
     * a Message saves on every edit, tag, entity attach and lock flip, and each of those would
     * otherwise cost an approval_policies lookup to learn there is no ON_CREATE policy.
     */
    protected static function approvalUsesLifecycleTriggers(): bool
    {
        return true;
    }

    public function approvalRequests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class, 'entity_id', $this->getKeyName())
            ->where('system_modules_id', $this->approvalSystemModuleId())
            ->where('is_deleted', false);
    }

    public function approvalSystemModuleId(): int
    {
        $app = $this->app ?? app(Apps::class);
        $cacheKey = static::class . '|' . $app->getId();

        return self::$approvalSystemModuleIds[$cacheKey] ??= SystemModulesRepository::getByModelName(
            static::class,
            $app
        )->getId();
    }

    public function pendingApproval(?string $approvalType = null): ?ApprovalRequest
    {
        /** @var ApprovalRequest|null $request */
        $request = $this->approvalRequests()
            ->where('status', ApprovalStatusEnum::PENDING)
            ->when($approvalType, fn ($query) => $query->where('approval_type', $approvalType))
            ->latest('id')
            ->first();

        return $request;
    }

    /**
     * Plural counterpart of pendingApproval() — every pending request, not just the latest one.
     * An entity can carry more than one pending approval_type at a time (a People, for example,
     * may have both an approve_people and an approve_people_salesforce_create/update request open
     * together), so callers that need to see all of them use this instead of picking just one.
     */
    public function pendingApprovals(?string $approvalType = null): Collection
    {
        return $this->approvalRequests()
            ->where('status', ApprovalStatusEnum::PENDING)
            ->when($approvalType, fn ($query) => $query->where('approval_type', $approvalType))
            ->latest('id')
            ->get();
    }

    public function isApproved(): bool
    {
        return $this->approvalRequests()
            ->where('status', ApprovalStatusEnum::APPROVED)
            ->exists();
    }

    /**
     * Who signed this off. Null for an entity that was never gated, is still pending, or was closed
     * by a rule rather than a person — an auto-approved request has no resolving user by design.
     */
    public function approvedBy(): ?Users
    {
        /** @var ApprovalRequest|null $request */
        $request = $this->approvalRequests()
            ->where('status', ApprovalStatusEnum::APPROVED)
            ->latest('resolved_at')
            ->first();

        return $request?->resolvedByUser;
    }

    /**
     * Closes an existing pending request of the given type without a human, because a newer one is
     * about to replace it — a returning Lead, a re-submitted form, anything where "the old one is
     * simply obsolete now" is the correct read, not "nobody decided". A no-op when nothing is pending.
     *
     * Whether to call this at all stays the caller's decision (a duplicate pending might be exactly
     * what should block a new request for one entity and be fine to supersede for another) — this only
     * generalizes the mechanics of closing the stale one via SystemRejectAction, not when to use it.
     */
    public function supersedePendingApproval(
        string $approvalType,
        string $reason = 'Superseded by a more recent approval request'
    ): void {
        $stale = $this->pendingApproval($approvalType);

        if ($stale !== null) {
            new SystemRejectAction($stale, $reason)->execute();
        }
    }

    /**
     * Opens an approval for this record. Returns null when the tenant has configured no policy for
     * this approval type — an ungated entity is the normal case and must not be an error.
     *
     * `origin` is passed, never sniffed: there is no ambient "current agent" to read, and an agent's
     * user is shared across agents so the actor can't be inferred from it either. A policy that needs
     * to gate on provenance without a caller threading it should condition on the entity's own data.
     */
    public function requestApproval(
        string $approvalType,
        array $payload = [],
        ?UserInterface $requestedBy = null,
        ApprovalOriginEnum $origin = ApprovalOriginEnum::SYSTEM
    ): ?ApprovalRequest {
        $policy = ApprovalPolicyRepository::findByType($this, $approvalType);

        if ($policy === null) {
            return null;
        }

        return new RequestApprovalAction(
            entity: $this,
            policy: $policy,
            origin: $origin,
            requestedBy: $requestedBy,
            payload: $payload,
        )->execute();
    }

    /**
     * Conveniences over ApproveAction/RejectAction so command and tinker code reads well. They add no
     * privilege: the approver-row check inside the action still runs.
     */
    public function approve(UserInterface $approver, ?string $comment = null, ?string $approvalType = null): ApprovalResult
    {
        return new ApproveAction($this->pendingApprovalOrFail($approvalType), $approver, $comment)->execute();
    }

    public function reject(UserInterface $approver, string $reason, ?string $approvalType = null): ApprovalResult
    {
        return new RejectAction($this->pendingApprovalOrFail($approvalType), $approver, $reason)->execute();
    }

    private function pendingApprovalOrFail(?string $approvalType = null): ApprovalRequest
    {
        return $this->pendingApproval($approvalType) ?? throw new ValidationException(
            static::class . ' ' . $this->getKey() . ' has no pending approval.'
        );
    }

    /**
     * The seatbelt: call this from whatever action performs the gated side effect. The real guarantee
     * is that the side effect lives in the policy's handler and has no other caller — this catches the
     * call site added later that forgot.
     */
    public function assertApproved(?string $approvalType = null): void
    {
        if ($this->pendingApproval($approvalType) !== null) {
            throw new ApprovalRequiredException(
                static::class . ' ' . $this->getKey() . ' is awaiting approval and cannot be processed yet.'
            );
        }
    }
}
