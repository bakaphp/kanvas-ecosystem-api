<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Kanvas\Workflow\Enums\WorkflowEnum;

/**
 * Without this row the event is a silent no-op: ProcessWorkflowEventAction resolves the trigger via
 * RuleType::getByName() and returns null on ModelNotFoundException, so no rule could be attached and a
 * classification would never reach a connector.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $name = WorkflowEnum::PEOPLE_CLASSIFIED->value;

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
            ->where('name', WorkflowEnum::PEOPLE_CLASSIFIED->value)
            ->delete();
    }
};
