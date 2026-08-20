<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Kanvas\Workflow\Enums\WorkflowEnum;

/**
 * The trigger a WhatsApp group burst fires on.
 *
 * Without this row the event is a silent no-op: ProcessWorkflowEventAction resolves the trigger via
 * RuleType::getByName() and returns null on ModelNotFoundException, so ProcessGroupBurstJob would
 * announce a finished burst that no rule could ever be attached to.
 *
 * Deliberately separate from `after-adding-message-to-channel`: the DM responder wired to that
 * trigger reads `$message->entity()` as a Lead without guarding, and a group message hands it a
 * Channel.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $name = WorkflowEnum::AFTER_ADDING_MESSAGE_TO_GROUP_CHANNEL->value;

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
            ->where('name', WorkflowEnum::AFTER_ADDING_MESSAGE_TO_GROUP_CHANNEL->value)
            ->delete();
    }
};
