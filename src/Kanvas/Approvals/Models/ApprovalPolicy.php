<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Approvals\Contracts\ApprovalHandlerInterface;
use Kanvas\Approvals\DataTransferObject\ApprovalStep;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Models\BaseModel;
use Kanvas\SystemModules\Models\SystemModules;

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
class ApprovalPolicy extends BaseModel
{
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

    /**
     * Null when the policy names no handler, or names a class that no longer exists or does not
     * implement the contract — a stale handler reference must not stop the approval being recorded.
     */
    public function handlerInstance(): ?ApprovalHandlerInterface
    {
        if ($this->handler === null || ! class_exists($this->handler)) {
            return null;
        }

        $handler = app($this->handler);

        return $handler instanceof ApprovalHandlerInterface ? $handler : null;
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
