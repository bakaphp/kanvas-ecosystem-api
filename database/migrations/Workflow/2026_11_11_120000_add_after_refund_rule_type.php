<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Kanvas\Workflow\Enums\WorkflowEnum;

/**
 * Fired on a payable (an Order) when one of its payment refunds completes. Without this row,
 * ProcessWorkflowEventAction treats `after-refund` as an unknown event and silently returns.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $name = WorkflowEnum::AFTER_REFUND->value;

        if (DB::connection('workflow')->table('rules_types')->where('name', $name)->exists()) {
            return;
        }

        DB::connection('workflow')->table('rules_types')->insert([
            'name' => $name,
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')
            ->table('rules_types')
            ->where('name', WorkflowEnum::AFTER_REFUND->value)
            ->delete();
    }
};
