<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Kanvas\Workflow\Enums\WorkflowEnum;

/**
 * The trigger the migrated Intellicheck ID-verification path fires on.
 *
 * Separate from `after-id-verification` rather than replacing it: that verb is still fired by the
 * legacy Phalcon controller and the current frontend, neither of which sends the target engagement,
 * so its rules stay untouched until both producers migrate. Without this row
 * `ProcessWorkflowEventAction::execute()` swallows the ModelNotFoundException and returns null, so the
 * new verb would fire, run nothing, and report nothing anywhere.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $name = WorkflowEnum::GENERATE_ID_VERIFICATION->value;

        $exists = DB::connection('workflow')
            ->table('rules_types')
            ->where('name', $name)
            ->exists();

        if ($exists) {
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
            ->where('name', WorkflowEnum::GENERATE_ID_VERIFICATION->value)
            ->delete();
    }
};
