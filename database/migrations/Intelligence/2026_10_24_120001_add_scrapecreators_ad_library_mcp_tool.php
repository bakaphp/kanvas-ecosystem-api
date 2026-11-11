<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * Titled for what the person connects it for — the two public ad libraries — and suffixed with the vendor
 * because the data is scraped by a third party, as the Pipeboard rows name their broker.
 */
return new class () extends Migration {
    private const string SLUG = 'scrapecreators_ad_library_mcp';

    private const string TITLE = 'Ad Libraries (ScrapeCreators)';

    private const string DESCRIPTION = 'Public ad records from the Meta Ad Library and the Google Ads Transparency Center through '
        . 'ScrapeCreators — find an advertiser or page by name, list the ads a brand is running now on Facebook, '
        . 'Instagram and Google, search Meta ads by keyword, open one ad for its creative, copy, landing page, start '
        . 'date, formats and platforms, and transcribe a video ad. Use it for competitor research, creative '
        . 'inspiration, checking whether a brand is advertising in a market, and seeing how long an ad has been '
        . 'live. This is the public library: spend, reach and demographics only exist for political and EU-delivered '
        . 'ads, and nothing here touches the connected person\'s own ad accounts — use the Meta Ads or Google Ads '
        . 'tools for that. The same server also carries ScrapeCreators\' social-media scrapers (TikTok, Instagram, '
        . 'YouTube, Reddit, …); reach for those only when the person asks about a creator or a post, not an ad. '
        . 'Every call spends credits from the connected ScrapeCreators account, so ask for one brand or one search '
        . 'at a time and go wider only when asked. Report the ads the tools returned; never invent an ad, a spend '
        . 'figure or a start date they did not. Connect it with the Connect button — it asks for your ScrapeCreators '
        . 'API key on the vendor\'s page — or paste the key directly (free tier available at app.scrapecreators.com).';

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
