<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * The grantable catalog rows for Google Analytics — `neuron` only (the Claude Managed Agents bridge does
 * not carry MCP rows yet).
 *
 * Both descriptions say "read only" plainly: the servers cannot change a property or a setting, and an
 * agent that believes otherwise will promise a change it can never make.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    /** Integration slug => [catalog title, description]. */
    private const array TOOLS = [
        'google_analytics_mcp' => [
            'Google Analytics',
            'Google Analytics over MCP — READ ONLY reporting on the connected Google account: custom reports over event data, with the dimensions, metrics, filters and date ranges a property exposes. It cannot change any Analytics configuration. Pair it with Google Analytics Admin to find the property id first.',
        ],
        'google_analytics_admin_mcp' => [
            'Google Analytics Admin',
            'Google Analytics Admin over MCP — READ ONLY discovery of the Analytics accounts and properties the connected Google account can see, which is how an agent finds the property id a report needs. It cannot create or edit accounts, properties or settings.',
        ],
    ];

    public function up(): void
    {
        foreach (self::TOOLS as $slug => [$title, $description]) {
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
            ->whereIn('name', array_column(self::TOOLS, 0))
            ->where('tool_type', ToolTypeEnum::MCP->value)
            ->where('apps_id', 0)
            ->delete();
    }
};
