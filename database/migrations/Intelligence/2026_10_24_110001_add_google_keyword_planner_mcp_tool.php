<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

return new class () extends Migration {
    private const string SLUG = 'google_keyword_planner_mcp';

    private const string TITLE = 'Google Keyword Planner';

    private const string DESCRIPTION = 'Google Ads Keyword Planner data through Apify — keyword ideas from one to five seed terms with '
        . 'average monthly searches, competition and top-of-page bid ranges, historical search volume for a keyword '
        . 'list with a monthly breakdown, month-over-month movement, and the location ids Google Ads targets. Use it '
        . 'to size demand, pick and group keywords, compare terms, and estimate what a click costs before a campaign '
        . 'is built. The planner tool starts an Apify run and answers with the run and its datasetId, not the rows: '
        . 'follow the `nextStep` it returns and call `get-dataset-items` with that datasetId to read the results, '
        . 'and if the run is still RUNNING check it once with `get-actor-run` before reading. Each planner call is '
        . 'billed to the connected Apify account, so send several seeds in one call rather than one call per '
        . 'seed, and report Google\'s figures as returned — volumes are averages and ranges, not guarantees. '
        . 'Connect it with the Connect button — it signs in with an Apify account — or paste an Apify API token.';

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
