<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

return new class () extends Migration {
    private const string SLUG = 'typesense_cloud_mcp';

    private const string TITLE = 'Typesense Cloud';

    private const string DESCRIPTION = 'Typesense Cloud over MCP — the account\'s search clusters and the data '
        . 'inside them: list clusters with their status, metrics and parameters; create collections, index, '
        . 'update and delete documents; search (keyword, vector and hybrid); and manage synonyms, curation, '
        . 'presets, stopwords, stemming, analytics rules and models. It cannot create, resize, clone or '
        . 'terminate a cluster or touch billing, so never promise one of those. Large imports and every '
        . 'export come back as a curl command meant for a person\'s own machine: relay it, never claim to '
        . 'have run it. Connect with the Connect button (signs in with the Typesense Cloud account) or paste '
        . 'a Cluster Management API key, which unlocks the cluster tools only.';

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
