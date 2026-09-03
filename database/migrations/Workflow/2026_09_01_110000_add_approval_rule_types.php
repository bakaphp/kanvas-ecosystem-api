<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Kanvas\Workflow\Enums\WorkflowEnum;

/**
 * The triggers the approvals domain fires on. Without a rules_types row the event is a silent no-op:
 * ProcessWorkflowEventAction resolves the trigger via RuleType::getByName() and returns null on
 * ModelNotFoundException, so an approval would announce itself to nothing.
 */
return new class () extends Migration {
    private const array RULE_TYPES = [
        WorkflowEnum::APPROVAL_REQUESTED,
        WorkflowEnum::APPROVAL_STEP_COMPLETED,
        WorkflowEnum::APPROVAL_UNASSIGNED,
        WorkflowEnum::APPROVAL_EXPIRED,
        WorkflowEnum::APPROVAL_CANCELLED,
        WorkflowEnum::APPROVED,
        WorkflowEnum::REJECTED,
    ];

    protected $connection = 'workflow';

    public function up(): void
    {
        foreach (self::RULE_TYPES as $ruleType) {
            $exists = DB::connection('workflow')
                ->table('rules_types')
                ->where('name', $ruleType->value)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::connection('workflow')->table('rules_types')->insert([
                'name' => $ruleType->value,
                'is_deleted' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('workflow')
            ->table('rules_types')
            ->whereIn('name', array_map(static fn (WorkflowEnum $t): string => $t->value, self::RULE_TYPES))
            ->delete();
    }
};
