<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Kanvas\Workflow\Enums\WorkflowEnum;

return new class () extends Migration {
    private const array RULE_TYPES = [
        WorkflowEnum::PASS_SCAN_REQUESTED,
        WorkflowEnum::PASS_SCANNED,
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
            ->whereIn('name', array_map(fn (WorkflowEnum $type): string => $type->value, self::RULE_TYPES))
            ->delete();
    }
};
