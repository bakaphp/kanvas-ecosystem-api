<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * @var array<int, string>
     */
    private array $descriptions = [
        // Ecosystem — internal platform foundation
        1 => 'Platform foundation — auth, identity, and multi-tenancy that everything else runs on.',
        // Inventory
        2 => 'Catalog & stock. Agents can quote, reserve, and adjust inventory.',
        // CRM
        3 => 'Agents can look up, create, and move leads and contacts in the CRM.',
        // Social / Channels
        4 => 'Agents listen and respond on your channels — chats, comments, and DMs.',
        // Workflow / Rules
        5 => 'Rules and automations your agents fire to keep work moving.',
        // Action Engine — internal nervous system
        6 => 'The nervous system itself — where agents act, dispatch jobs, and trigger integrations.',
        // AI
        10 => 'Models, prompts, and tools your agents use to reason and decide.',
        // Commerce
        11 => 'Orders, checkout, and fulfillment your agents can move along.',
    ];

    public function up(): void
    {
        foreach ($this->descriptions as $id => $description) {
            DB::connection('ecosystem')
                ->table('kanvas_modules')
                ->where('id', $id)
                ->update(['description' => $description]);
        }
    }

    public function down(): void
    {
        // No-op — the previous descriptions were a mix of NULL and
        // ad-hoc strings, so reverting would just churn the data back
        // into an inconsistent state. The forward migration is safe to
        // re-run.
    }
};
