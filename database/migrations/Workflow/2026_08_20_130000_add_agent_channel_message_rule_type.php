<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Kanvas\Workflow\Enums\WorkflowEnum;

/**
 * The trigger an assistant-mode 1:1 burst fires on.
 *
 * Separate from both existing message triggers: `after-adding-message-to-channel` responders read
 * `$message->entity()` as a Lead without guarding (an assistant message hands them a Channel), and
 * `after-adding-message-to-group-channel` would lie about the conversation shape.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $name = WorkflowEnum::AFTER_ADDING_MESSAGE_TO_AGENT_CHANNEL->value;

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
            ->where('name', WorkflowEnum::AFTER_ADDING_MESSAGE_TO_AGENT_CHANNEL->value)
            ->delete();
    }
};
