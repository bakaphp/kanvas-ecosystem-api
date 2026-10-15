<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

return new class () extends Migration {
    private const string SLUG = 'hyperframes_mcp';

    private const string TITLE = 'HyperFrames (HeyGen)';

    private const string DESCRIPTION = 'HeyGen HyperFrames over MCP — create and edit video compositions from a '
        . 'plain-language brief (product launches, explainers, motion graphics, slideshows, captioned talking-head '
        . 'cuts) and render them to MP4, WebM or MOV on HeyGen\'s hosted infrastructure. Keep every composition and '
        . 'render identifier a tool returns so later turns can refer to the same work. Rendering is user-gated: '
        . 'show the preview and get approval before rendering, and never claim a render finished unless the tool '
        . 'said so. Connect it with the Connect button — it signs in with your HeyGen account; renders count '
        . 'against that account\'s plan.';

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
