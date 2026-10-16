<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

return new class () extends Migration {
    private const string DESCRIPTION = 'Amplitude over MCP — ask product-analytics questions in plain '
        . 'language and get charts back, search and read dashboards, notebooks and saved charts, build '
        . 'cohorts, look up a user\'s event history, watch session replays, and read or change '
        . 'experiments, feature flags and the event taxonomy. Connect it with the Connect button — it '
        . 'signs in with your Amplitude account and only reaches the projects that account can.';

    private const array TOOLS = [
        'amplitude_mcp' => 'Amplitude',
        'amplitude_eu_mcp' => 'Amplitude (EU)',
    ];

    protected $connection = 'intelligence';

    public function up(): void
    {
        foreach (self::TOOLS as $slug => $title) {
            $integrationId = DB::connection('workflow')->table('integrations')
                ->where('name', $slug)
                ->where('apps_id', 0)
                ->value('id');

            $exists = DB::connection('intelligence')->table('nervous_system_tools')
                ->where('name', $title)
                ->where('apps_id', 0)
                ->exists();

            if ($integrationId === null || $exists) {
                continue;
            }

            $description = $slug === 'amplitude_eu_mcp'
                ? self::DESCRIPTION . ' Use this one only if your Amplitude org is on EU data residency.'
                : self::DESCRIPTION;

            DB::connection('intelligence')->table('nervous_system_tools')->insert([
                'uuid' => (string) Str::uuid(),
                'apps_id' => 0,
                'name' => $title,
                'description' => $description,
                'tool_type' => ToolTypeEnum::MCP->value,
                'handler' => null,
                'integrations_id' => $integrationId,
                'frameworks' => json_encode(['neuron']),
                'version' => '1.0.0',
                'is_active' => 1,
                'is_deleted' => 0,
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('intelligence')->table('nervous_system_tools')
            ->whereIn('name', array_values(self::TOOLS))
            ->where('tool_type', ToolTypeEnum::MCP->value)
            ->where('apps_id', 0)
            ->delete();
    }
};
