<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

return new class () extends Migration {
    private const string SLUG = 'google_trends_mcp';

    private const string TITLE = 'Google Trends';

    private const string DESCRIPTION = 'Google Trends data through HasData — relative search interest over time for one or more terms, '
        . 'interest by country, subregion, metro or city, and the top and rising related topics and queries, with '
        . 'Google Trends\' own filters: a location (`geo`, e.g. `US` or `US-CA`), a date range (`now 7-d`, `today 12-m`, '
        . '`today 5-y`, `all`, or two dates), a category, and a Google property (web, images, news, shopping, '
        . 'YouTube). Use it for seasonality, demand timing, comparing brands or products against each other, and '
        . 'finding what people search alongside a topic. Scores are relative (100 is the peak in the window), not '
        . 'search volumes — say so when you report them, and pass several terms in one call (`timeseries` and '
        . '`geoMap` take a comma-separated list) to compare them on the same scale. Each call spends credits from '
        . 'the connected HasData workspace, so batch terms rather than looping, and report what came back rather '
        . 'than filling gaps yourself. Connect it with the Connect button — it signs in with a HasData account — or '
        . 'paste a HasData API key (free tier available).';

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
