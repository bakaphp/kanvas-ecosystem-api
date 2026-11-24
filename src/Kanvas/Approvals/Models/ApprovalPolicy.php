<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Models;

use Baka\Casts\Json;
use Baka\Concerns\HasHandlerClass;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Approvals\Contracts\ApprovalHandlerInterface;
use Kanvas\Approvals\DataTransferObject\ApprovalStep;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Observers\ApprovalPolicyObserver;
use Kanvas\Models\BaseModel;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Override;

/**
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $system_modules_id
 * @property string $approval_type
 * @property array $steps
 * @property string|null $handler
 * @property ApprovalTriggerEnum $trigger
 * @property array|null $trigger_condition
 * @property string|null $trigger_event
 * @property string $reject_policy
 * @property string|null $fallback_resolver
 * @property array|null $fallback_config
 * @property string $notify
 * @property int|null $expires_after_hours
 * @property bool $allow_authority_override
 * @property bool $is_deleted
 */
#[ObservedBy([ApprovalPolicyObserver::class])]
class ApprovalPolicy extends BaseModel
{
    use EmitsLedgerEventsForEntity;
    use HasHandlerClass;

    private const array AUDITED = [
        'system_modules_id',
        'approval_type',
        'steps',
        'handler',
        'trigger',
        'trigger_condition',
        'trigger_event',
        'reject_policy',
        'fallback_resolver',
        'fallback_config',
        'notify',
        'expires_after_hours',
        'allow_authority_override',
    ];

    /**
     * Who is making the current write, for the `approvals.policy_changed` audit. A declared property, not
     * an attribute, so it is never persisted; the table has no "changed by" column and a policy write by
     * a seed command legitimately has nobody to name.
     */
    public ?Users $changedBy = null;

    protected $table = 'approval_policies';
    protected $guarded = [];

    protected $casts = [
        'is_deleted' => 'boolean',
        'allow_authority_override' => 'boolean',
        'trigger' => ApprovalTriggerEnum::class,
        'steps' => Json::class,
        'trigger_condition' => Json::class,
        'fallback_config' => Json::class,
    ];

    public function triggerName(): string
    {
        return $this->trigger->value;
    }

    public function systemModule(): BelongsTo
    {
        return $this->belongsTo(SystemModules::class, 'system_modules_id', 'id');
    }

    /**
     * @return list<ApprovalStep>
     */
    public function approvalSteps(): array
    {
        $steps = [];

        foreach (array_values((array) $this->steps) as $index => $definition) {
            $steps[] = ApprovalStep::fromArray((array) $definition, $index + 1);
        }

        usort($steps, static fn (ApprovalStep $a, ApprovalStep $b): int => $a->step <=> $b->step);

        return $steps;
    }

    #[Override]
    protected static function handlerContracts(): array
    {
        return [ApprovalHandlerInterface::class];
    }

    /**
     * Null when the policy names no handler, or names a class that no longer exists or does not
     * implement the contract — a stale handler reference must not stop the approval being recorded.
     */
    public function handlerInstance(): ?ApprovalHandlerInterface
    {
        $handlerClass = $this->handlerClass();

        return $handlerClass === null ? null : app($handlerClass);
    }

    /**
     * @return array<string, mixed>
     */
    public function auditedAttributes(bool $original = false): array
    {
        $values = [];

        foreach (self::AUDITED as $key) {
            $value = $original ? $this->getOriginal($key) : $this->getAttribute($key);
            $values[$key] = $value instanceof ApprovalTriggerEnum ? $value->value : $value;
        }

        return $values;
    }

    protected function sourceDomainForLedger(): string
    {
        return 'Approvals';
    }

    protected function resolveDefaultActorType(): string
    {
        return $this->changedBy !== null ? 'User' : 'System';
    }

    protected function resolveDefaultActorId(): ?int
    {
        return $this->changedBy?->getId();
    }

    public function stepAt(int $step): ?ApprovalStep
    {
        foreach ($this->approvalSteps() as $approvalStep) {
            if ($approvalStep->step === $step) {
                return $approvalStep;
            }
        }

        return null;
    }
}
