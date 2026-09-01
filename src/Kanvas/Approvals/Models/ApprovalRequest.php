<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kanvas\Approvals\Enums\ApprovalDecisionEnum;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Observers\ApprovalRequestObserver;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Models\BaseModel;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Traits\CanUseWorkflow;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $system_modules_id
 * @property int $entity_id
 * @property string $approval_type
 * @property int|null $approval_policies_id
 * @property ApprovalOriginEnum|null $origin
 * @property ApprovalStatusEnum $status
 * @property int $current_step
 * @property int|null $requested_by_users_id
 * @property int|null $requested_by_agent_id
 * @property array|null $payload
 * @property int|null $resolved_by_users_id
 * @property Carbon|null $resolved_at
 * @property string|null $reason
 * @property Carbon|null $expires_at
 * @property array|null $metadata
 * @property bool $is_deleted
 */
#[ObservedBy([ApprovalRequestObserver::class])]
class ApprovalRequest extends BaseModel
{
    use CanUseWorkflow;
    use EmitsLedgerEventsForEntity;
    use UuidTrait;

    // Kanvas soft-delete fires its own event; without declaring it the observer never runs.
    protected $observables = [
        'softDeleted',
    ];

    protected $table = 'approval_requests';
    protected $guarded = [];

    protected $casts = [
        'is_deleted' => 'boolean',
        'status' => ApprovalStatusEnum::class,
        'origin' => ApprovalOriginEnum::class,
        'payload' => Json::class,
        'metadata' => Json::class,
        'resolved_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function approvers(): HasMany
    {
        return $this->hasMany(ApprovalRequestApprover::class, 'approval_requests_id', 'id')
            ->where('is_deleted', false);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ApprovalPolicy::class, 'approval_policies_id', 'id');
    }

    public function systemModule(): BelongsTo
    {
        return $this->belongsTo(SystemModules::class, 'system_modules_id', 'id');
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'requested_by_users_id', 'id');
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'resolved_by_users_id', 'id');
    }

    /**
     * The record under approval. Null when its system module points at a class that no longer exists
     * or the row was hard-deleted — callers must handle that rather than assume a live entity.
     */
    public function resolveEntity(): ?Model
    {
        $modelName = $this->systemModule?->model_name;

        if ($modelName === null || ! class_exists($modelName)) {
            return null;
        }

        return $modelName::query()->find($this->entity_id);
    }

    protected function sourceDomainForLedger(): string
    {
        return 'Approvals';
    }

    /**
     * The trait's defaults read users_id/agent_id, which this table does not have — every approval
     * would be filed against 'System' and the ledger could not answer who signed. The actor is
     * whoever resolved it, falling back to whoever asked.
     */
    protected function resolveDefaultActorType(): string
    {
        return $this->resolveDefaultActorId() !== null ? 'User' : 'System';
    }

    protected function resolveDefaultActorId(): ?int
    {
        return $this->resolved_by_users_id ?? $this->requested_by_users_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function ledgerPayload(): array
    {
        return [
            'approval_type' => $this->approval_type,
            'status' => $this->status->value,
            'origin' => $this->origin?->value,
            'current_step' => $this->current_step,
            'entity_type' => $this->systemModule?->model_name,
            'entity_id' => $this->entity_id,
            'requested_by_users_id' => $this->requested_by_users_id,
            'resolved_by_users_id' => $this->resolved_by_users_id,
            'reason' => $this->reason,
            // What the sync handler actually did downstream — an ERP reference, or the error that
            // stopped it. "Approved" and "landed in the ERP" are different facts.
            'result' => $this->metadata['handler_result'] ?? null,
            'approvers' => $this->approvers()
                ->get(['users_id', 'email', 'step', 'decision'])
                ->map(fn (ApprovalRequestApprover $row): array => [
                    'users_id' => $row->users_id,
                    'email' => $row->email,
                    'step' => $row->step,
                    'decision' => $row->decision->value,
                ])
                ->all(),
        ];
    }

    /**
     * The caller's live approver row, or a refusal. Authorization for any decision is this row and
     * nothing else — not a Bouncer ability (someone with `edit` on an Invoice must not thereby be able
     * to approve one) and not a row sitting at a step that has not become live yet.
     */
    public function requireApproverRow(UserInterface $user): ApprovalRequestApprover
    {
        /** @var ApprovalRequestApprover|null $row */
        $row = $this->approvers()
            ->where('users_id', $user->getId())
            ->where('step', $this->current_step)
            ->where('decision', ApprovalDecisionEnum::PENDING)
            ->first();

        if ($row === null) {
            throw new ValidationException(
                'You are not an approver for this request at its current step.'
            );
        }

        return $row;
    }

    public function requiredApprovalsAtCurrentStep(): int
    {
        return $this->policy?->stepAt($this->current_step)?->requiredApprovals ?? 1;
    }

    public function approvalsAtCurrentStep(): int
    {
        return $this->approvers()
            ->where('step', $this->current_step)
            ->whereIn('decision', [ApprovalDecisionEnum::APPROVED, ApprovalDecisionEnum::AUTO_APPROVED])
            ->count();
    }

    /**
     * Conditional close: flips the row only if it is still pending, so two callers racing to resolve
     * the same request cannot both win and run the policy's handler twice. Every path that ends a
     * request goes through here — approve, reject, cancel, expire.
     *
     * @param array<string, mixed> $attributes
     */
    public function claimIfPending(ApprovalStatusEnum $status, array $attributes = []): bool
    {
        $claimed = static::query()
            ->where('id', $this->getId())
            ->where('status', ApprovalStatusEnum::PENDING->value)
            ->update(['status' => $status->value, ...$attributes]);

        return $claimed === 1;
    }

    /**
     * Nobody else needs to answer a request that is already closed.
     */
    public function skipUndecidedApprovers(): void
    {
        $this->approvers()
            ->whereIn('decision', [ApprovalDecisionEnum::PENDING, ApprovalDecisionEnum::WAITING])
            ->update(['decision' => ApprovalDecisionEnum::SKIPPED->value]);
    }

    /**
     * The lowest step above the current one that still has someone to ask. Steps whose `when` failed
     * were written as SKIPPED at request time, so they are simply absent here.
     */
    public function nextLiveStep(): ?int
    {
        $step = $this->approvers()
            ->where('step', '>', $this->current_step)
            ->whereIn('decision', [ApprovalDecisionEnum::WAITING, ApprovalDecisionEnum::PENDING])
            ->min('step');

        return $step !== null ? (int) $step : null;
    }

    /**
     * @return list<string>
     */
    public function pendingApproverEmails(): array
    {
        return $this->approvers()
            ->where('step', $this->current_step)
            ->where('decision', ApprovalDecisionEnum::PENDING)
            ->pluck('email')
            ->filter(static fn (?string $email): bool => $email !== null && trim($email) !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * GraphQL String fields cannot serialize a backed enum instance, so the schema reads these rather
     * than the cast attributes directly — same shape as OrganizationAddress::addressTypeName().
     */
    public function statusName(): string
    {
        return $this->status->value;
    }

    public function originName(): ?string
    {
        return $this->origin?->value;
    }

    /**
     * A curated summary of the record under approval, for a UI that has to render "what am I
     * approving" without knowing the type. Deliberately not the raw model: `Mixed` would serialize
     * every column of a bill or an invoice into a payload that is read by whoever can see the
     * request, which is a wider audience than the record itself has.
     *
     * @return array<string, mixed>|null
     */
    public function entitySummary(): ?array
    {
        $entity = $this->resolveEntity();

        if ($entity === null) {
            return null;
        }

        return [
            'id' => $entity->getKey(),
            'type' => $this->systemModule?->name ?? class_basename($entity),
            'label' => $entity->name
                ?? $entity->bill_number
                ?? $entity->invoice_number
                ?? $entity->expense_number
                ?? (string) $entity->getKey(),
        ];
    }

    public function isUnassigned(): bool
    {
        return (bool) ($this->metadata['unassigned'] ?? false);
    }
}
