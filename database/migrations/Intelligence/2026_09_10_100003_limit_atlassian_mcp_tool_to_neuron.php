<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Atlassian row shipped advertising `claude`, but the Claude Managed Agents bridge drops toolkits
 * (it keeps only `ToolInterface` instances) and has no MCP credential path until PR5 — so a grant to a
 * Claude agent resolved to nothing. Advertise only what works.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        $this->setFrameworks(['neuron']);
    }

    public function down(): void
    {
        $this->setFrameworks(['neuron', 'claude']);
    }

    /**
     * @param list<string> $frameworks
     */
    private function setFrameworks(array $frameworks): void
    {
        DB::connection('intelligence')->table('nervous_system_tools')
            ->where('name', 'atlassian_mcp')
            ->where('apps_id', 0)
            ->update([
                'frameworks' => json_encode($frameworks),
                'updated_at' => now(),
            ]);
    }
};
