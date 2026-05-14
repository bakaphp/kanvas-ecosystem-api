<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Collapse `OPENCLAW_*` / `HERMES_*` channel-token custom-field rows onto the shared
// `AGENT_*` keys. When an agent already has the AGENT_* key set we skip the copy (don't
// overwrite a manual edit); legacy rows are soft-deleted, not hard-deleted, so the
// audit trail survives. `down()` is intentionally a no-op — ship a fresh forward
// migration if a revert is needed.
return new class () extends Migration {
    private const string TABLE = 'apps_custom_fields';

    // Order matters: OpenClaw rows are processed first, so on tied (agent, token) pairs
    // the OpenClaw value wins and the Hermes copy is dropped.
    private const array LEGACY_TO_SHARED = [
        'OPENCLAW_SLACK_BOT_TOKEN' => 'AGENT_SLACK_BOT_TOKEN',
        'OPENCLAW_SLACK_APP_TOKEN' => 'AGENT_SLACK_APP_TOKEN',
        'OPENCLAW_TELEGRAM_BOT_TOKEN' => 'AGENT_TELEGRAM_BOT_TOKEN',
        'HERMES_SLACK_BOT_TOKEN' => 'AGENT_SLACK_BOT_TOKEN',
        'HERMES_SLACK_APP_TOKEN' => 'AGENT_SLACK_APP_TOKEN',
        'HERMES_TELEGRAM_BOT_TOKEN' => 'AGENT_TELEGRAM_BOT_TOKEN',
    ];

    public function up(): void
    {
        foreach (self::LEGACY_TO_SHARED as $legacyKey => $sharedKey) {
            DB::table(self::TABLE)
                ->where('name', $legacyKey)
                ->where('is_deleted', 0)
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($sharedKey): void {
                    foreach ($rows as $row) {
                        $alreadyOnSharedKey = DB::table(self::TABLE)
                            ->where('model_name', $row->model_name)
                            ->where('entity_id', $row->entity_id)
                            ->where('name', $sharedKey)
                            ->where('is_deleted', 0)
                            ->exists();

                        if (! $alreadyOnSharedKey) {
                            DB::table(self::TABLE)->insert([
                                'companies_id' => $row->companies_id,
                                'users_id' => $row->users_id,
                                'model_name' => $row->model_name,
                                'entity_id' => $row->entity_id,
                                'name' => $sharedKey,
                                'label' => $sharedKey,
                                'value' => $row->value,
                                'created_at' => now(),
                                'updated_at' => now(),
                                'is_deleted' => 0,
                            ]);
                        }

                        DB::table(self::TABLE)
                            ->where('id', $row->id)
                            ->update([
                                'is_deleted' => 1,
                                'updated_at' => now(),
                            ]);
                    }
                });
        }
    }

    public function down(): void
    {
        // intentional no-op — see class comment.
    }
};
