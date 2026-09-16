<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * The grantable catalog rows for the Notion, Sentry, Stripe and Figma MCP servers — one per server,
 * `neuron` only (the Claude Managed Agents bridge does not carry MCP rows yet).
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    private const array TOOLS = [
        'notion_mcp' => 'Notion over MCP. Grants the agent every tool the connected Notion account allows — searching the workspace, and reading, creating and editing pages and databases.',
        'sentry_mcp' => 'Sentry over MCP. Grants the agent every tool the connected Sentry account allows — reading issues and events, triaging them, and inspecting projects.',
        'stripe_mcp' => 'Stripe over MCP. Grants the agent every tool the connected Stripe account allows — reading customers, payments, invoices and subscriptions, and searching the Stripe docs and API.',
        'figma_mcp' => 'Figma over MCP. Grants the agent every tool the connected Figma account allows — reading design files, components, variables and Dev Mode context, and generating code from frames.',
    ];

    public function up(): void
    {
        foreach (self::TOOLS as $name => $description) {
            $integrationId = DB::connection('workflow')->table('integrations')
                ->where('name', $name)
                ->where('apps_id', 0)
                ->value('id');

            $exists = DB::connection('intelligence')->table('nervous_system_tools')
                ->where('name', $name)
                ->where('apps_id', 0)
                ->exists();

            if ($integrationId === null || $exists) {
                continue;
            }

            DB::connection('intelligence')->table('nervous_system_tools')->insert([
                'uuid' => (string) Str::uuid(),
                'apps_id' => 0,
                'name' => $name,
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
            ->whereIn('name', array_keys(self::TOOLS))
            ->where('apps_id', 0)
            ->delete();
    }
};
