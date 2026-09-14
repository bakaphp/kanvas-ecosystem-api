<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registers Claude Managed Agents against the generic integration mechanism. Setup runs through the
 * shared `integrationCompany` mutation, which reads `handler` from this row and calls
 * ClaudeAgentHandler::setup() — validating the key against the API before storing it on the company.
 *
 * Per-agent config (remote agent id, GitHub token, allowed repos, session budget) is agent-scoped
 * and set through the generic `setAgentSetting` surface, not here.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $exists = DB::connection('workflow')
            ->table('integrations')
            ->where('name', 'claude-agent')
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        $config = [
            'api_key' => ['type' => 'text', 'required' => true],
            // Only set when pointing at something other than the public API.
            'base_url' => ['type' => 'text', 'required' => false],
        ];

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'claude-agent',
            'handler' => 'Kanvas\\Connectors\\ClaudeAgent\\Handlers\\ClaudeAgentHandler',
            'apps_id' => 0,
            'config' => json_encode($config),
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', 'claude-agent')
            ->where('apps_id', 0)
            ->delete();
    }
};
