<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Migrations\AiMigration;

/**
 * The point of no return of the Laravel AI 1.x move: the pre-1.x `tool_calls` / `tool_results` columns go.
 * Built for a single deploy: when rows still have no `steps`, the backfill command runs here first (one
 * implementation, the same one a manual run uses), and only a clean table is altered. A row left behind
 * stops the migration with the count rather than letting MySQL fail halfway through the ALTER. `steps`
 * stays nullable on purpose — the application always writes it, and a NOT NULL change would rebuild the
 * whole table where the drop alone is instant DDL. `down()` restores the columns empty; the data is not
 * recoverable.
 */
return new class () extends AiMigration {
    public function getConnection(): ?string
    {
        return 'intelligence';
    }

    public function up(): void
    {
        if ($this->rowsWithoutSteps() > 0) {
            Artisan::call('agents:backfill-conversation-steps');
        }

        $missing = $this->rowsWithoutSteps();

        if ($missing > 0) {
            throw new RuntimeException(sprintf(
                '%d agent_conversation_messages rows still have steps = NULL after the backfill. Run `php artisan agents:backfill-conversation-steps` and look at what it leaves behind before dropping the legacy columns.',
                $missing,
            ));
        }

        Schema::connection('intelligence')->table('agent_conversation_messages', function (Blueprint $table) {
            $table->dropColumn(['tool_calls', 'tool_results']);
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('agent_conversation_messages', function (Blueprint $table) {
            $table->longText('tool_calls')->nullable()->after('attachments');
            $table->longText('tool_results')->nullable()->after('tool_calls');
        });
    }

    private function rowsWithoutSteps(): int
    {
        return DB::connection('intelligence')->table('agent_conversation_messages')->whereNull('steps')->count();
    }
};
