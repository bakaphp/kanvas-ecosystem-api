<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * Lands active, like every other hand-made-client row (GitHub, Slack, HubSpot): until the app admin sets
 * `mcp_oauth_client_id_meta`, Connect fails with a message that names that setting and the callback to
 * register — actionable, not opaque. Nobody here has completed a live consent against a Meta app yet;
 * if the first one is refused on Meta's side, the one-line reversal is `is_active = 0`.
 *
 * Titled "Meta Developer Tools" rather than Meta's "Social Technologies": the agent-facing name has to
 * say what it does.
 */
return new class () extends Migration {
    private const string SLUG = 'meta_devtools_mcp';

    private const string TITLE = 'Meta Developer Tools';

    private const string DESCRIPTION = 'Meta Developer Tools over MCP — READ ONLY on the Meta apps the connected '
        . 'developer account administers: app settings and permissions, App Review status and approved '
        . 'permissions, compliance status, API usage, rate limits and deprecations, webhook topics and active '
        . 'subscriptions, the API changelog, and a search over Meta\'s developer documentation. It cannot '
        . 'create, change or delete webhook subscriptions, so never promise a webhook change here. Connect '
        . 'with the Connect button (signs in with a Meta developer account through the company\'s Meta app) '
        . 'or paste a user access token that carries the developer_tools_mcp_app_read permission.';

    protected $connection = 'intelligence';

    public function up(): void
    {
        $integrationId = DB::connection('workflow')->table('integrations')
            ->where('name', self::SLUG)
            ->where('apps_id', 0)
            ->value('id');

        $exists = DB::connection('intelligence')->table('nervous_system_tools')
            ->where('name', self::TITLE)
            ->where('apps_id', 0)
            ->exists();

        if ($integrationId === null || $exists) {
            return;
        }

        DB::connection('intelligence')->table('nervous_system_tools')->insert([
            'uuid' => (string) Str::uuid(),
            'apps_id' => 0,
            'name' => self::TITLE,
            'description' => self::DESCRIPTION,
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

    public function down(): void
    {
        DB::connection('intelligence')->table('nervous_system_tools')
            ->where('name', self::TITLE)
            ->where('tool_type', ToolTypeEnum::MCP->value)
            ->where('apps_id', 0)
            ->delete();
    }
};
