<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * The public ad libraries — Meta Ad Library and Google Ads Transparency Center — through ScrapeCreators'
 * hosted server. Neither platform offers them over MCP: Google publishes no Transparency Center API at all,
 * and Meta's `mcp.facebook.com/ads` is the advertiser's OWN accounts (`ads_insights_*`, `ads_get_ad_entities`)
 * with no `ads_archive` tool. Every remote option is therefore a scraper or a scraper reseller, and
 * ScrapeCreators is the vendor the community servers (facebook-ads-library-mcp, ad-library-mcp, Proxy's
 * ancestor) wrap — so this row goes to the source rather than to a reseller of it.
 *
 * Why not the alternatives (all probed 2026-10-24):
 *  - Proxy (`mcp.useproxy.dev/mcp`) has the cleanest tool set (`get_meta_ads`, `get_google_ads`, …) but is the
 *    Vercel kind: `/register` takes any redirect URI and `/authorize` then refuses it — "does not match allowed
 *    patterns" for `{app.url}/v1/oauth/callback` on two hosts, while `claude.ai/api/mcp/auth_callback` and
 *    `localhost` pass. OAuth only, no key. Unconnectable from Kanvas until they allow-list us.
 *  - Prowl (`prowl.chat/mcp`) reaches the same data (`meta_ad_library`, `google_ads_transparency_*`) but only
 *    through `prowl_search_tools` → `prowl_tool_info` → `prowl_call_tool(name, params)`, so the model never
 *    sees a typed schema for the ad tools. A meta-tool server is a poor fit for the registry.
 *  - Apify Actors (`apify/facebook-ads-scraper`, `maximedupre/google-ads-scraper`) are bulk pulls by URL that
 *    answer with a run and a dataset id, priced per thousand ads — right for an export, wrong for "what is this
 *    brand running right now".
 *
 * ScrapeCreators is the clean kind: `initialize` → 401 naming the path-aware protected-resource document (the
 * origin-level one 404s) → `api.scrapecreators.com` is its own authorization server, RFC 8414 at the origin root,
 * PKCE S256, public clients (`none`), `refresh_token` grant, one scope (`mcp:tools`), and `/register` took
 * `{app.url}/v1/oauth/callback` first try — one click. `/authorize` validates the resource (`bogus` →
 * `invalid_target: Invalid MCP resource`) and accepts `https://api.scrapecreators.com/mcp`; it does NOT
 * validate scope (a bogus one reaches the consent page), so only the advertised `mcp:tools` is requested. The
 * consent page asks the person for their ScrapeCreators API key — OAuth here is a key wrapped in a grant, which
 * is why the pasted-key method (`x-api-key`, the header its REST API uses) is offered beside it.
 *
 * Tool list unprobed: `tools/list` itself answers `Authorization required`. The server is expected to expose the
 * vendor's REST catalogue (Facebook Ad Library search/company/ad/transcript, Google Ad Library company/ad/
 * advertiser search) AND its 27 social-platform scrapers (TikTok, Instagram, YouTube, Reddit, …). Nothing is
 * excluded blind; on the first real listing, decide whether the social scrapers stay on an "ad libraries" row
 * or move to their own. Every call spends the account's credits (~$1.88 per 1,000 Meta ads at the time of
 * writing). Nothing is expected to run in the background: the REST endpoints answer inline.
 *
 * Scraped data, not platform-sanctioned: Meta's only sanctioned path is the `ads_archive` Graph API (political
 * and EU ads with spend; commercial ads without it) and Google has none. Either platform can block the vendor's
 * backend at any time; a sudden `failed` on every grant is that, not a token problem.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const string NAME = 'scrapecreators_ad_library_mcp';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', self::NAME)
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => self::NAME,
            'handler' => McpHandler::class,
            'type' => IntegrationTypeEnum::MCP->value,
            'apps_id' => 0,
            'config' => json_encode([]),
            'metadata' => json_encode([
                'vendor' => 'Ad Libraries (ScrapeCreators)',
                'transport' => 'http',
                'url' => 'https://api.scrapecreators.com/mcp',
                'auth_methods' => [
                    'oauth',
                    'bearer',
                ],
                'auth_header' => 'x-api-key',
                'oauth' => [
                    'scopes' => ['mcp:tools'],
                ],
                'prefix' => 'adlib',
                'exclude' => [],
                'timeout_ms' => 60000,
                'url_per_connection' => false,
            ]),
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', self::NAME)
            ->where('type', IntegrationTypeEnum::MCP->value)
            ->where('apps_id', 0)
            ->delete();
    }
};
