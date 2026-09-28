<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * The grantable catalog rows for the DocuSign and WordPress MCP servers — `neuron` only (the Claude
 * Managed Agents bridge does not carry MCP rows yet).
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    /** Integration slug => [catalog title, description]. */
    private const array TOOLS = [
        'docusign_mcp' => [
            'DocuSign',
            'DocuSign over MCP — sending envelopes for signature, checking signing status, querying agreements through Navigator and triggering Maestro workflows, as whoever signs in. Still a DocuSign beta: production accounts need enrolment in their MCP beta programme.',
        ],
        'wordpress_mcp' => [
            'WordPress',
            'WordPress over MCP — reading and publishing content on one WordPress site through the MCP Adapter plugin it runs, typically at `/wp-json/mcp/mcp-adapter-default-server`. Connect it with that site\'s address. Kanvas also ships a native WordPress connector that publishes with an Application Password; grant that OR this, not both.',
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
