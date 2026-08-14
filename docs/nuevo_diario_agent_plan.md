# Research & Implementation Plan — "Nuevo Diario" News Agent/Scraper

Status: **planning document** — no production code has been written yet. This file is the
research output requested for building an agent/tool that ingests news from *El Nuevo
Diario* (Dominican Republic newspaper, `elnuevodiario.com.do`) into the Kanvas agent
ecosystem, plus (optionally) the Knowledge/RAG pipeline.

---

## 1. How agents / ingestion are structured today

Kanvas is a Laravel + GraphQL monorepo organized by domain under `src/Domains/{Domain}/`.
There is **no single "scrapers" folder** — scraping/ingestion lives in whichever domain
owns the data it produces, following one of three existing patterns. This section maps
all three so the new agent can be slotted into the right one.

### 1.1 Pattern A — Agent Tool (RSS/news lookup surfaced to an LLM agent)

This is the closest existing precedent to "Nuevo Diario" and the **recommended pattern**
for a first iteration (see §3).

- **Location:** `src/Domains/Intelligence/Agents/Laravel/Tools/News/GoogleNewsRssTool.php`
- **Interface:** `Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface`
  (extends `Laravel\Ai\Contracts\Tool`) — requires `name()`, `description()`, `handle()`,
  `schema()`, plus `withContext(Apps $app, Companies $company, ?Agent $agent = null)` from
  the `HasKanvasContext` trait.
- **Marker attribute:** `#[AgentTool(name: '...', category: 'knowledge')]` on the class —
  this is how the tool is auto-discovered (see §1.4) and synced into the
  `nervous_system_tools` catalog. No manual registry edit is needed; discovery scans
  `base_path('src')` and `base_path('app')` recursively via
  `src/Baka/Discovery/AttributeClassDiscovery.php`.
- **HTTP fetch:** `Baka\Http\SafeUrlFetcher::fetch($url)` — SSRF-guarded fetch (DNS-pinned,
  redirect re-validated, byte-capped). **Always use this**, never a raw `file_get_contents`
  or unguarded Guzzle client, for any tool that fetches an attacker-influenceable/external
  URL.
- **RSS parsing:** `simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA)`,
  then iterate `$feed->channel->item` reading `title`, `link`, `source`, `pubDate`.
  `LIBXML_NOCDATA` is important — WordPress feeds (which `elnuevodiario.com.do` runs on,
  see §4) wrap `description`/`content:encoded` in `CDATA`.
- **Errors never throw into the chat** — caught and returned as
  `json_encode(['error' => '...'])`; the LLM sees a calm, structured payload.
- **Test:** `tests/Intelligence/Agents/Tools/GoogleNewsRssToolTest.php` — builds the tool via
  `withContext()`, calls `handle(new Request([...]))`, decodes the JSON, and
  `markTestSkipped()`s assertions on network-dependent failures (the CI box may not be able
  to reach the public internet) rather than failing hard. This is the expected style for
  any new outbound-RSS tool test.
- Sibling **company-news** example with a different provider:
  `src/Domains/Intelligence/Agents/Laravel/Tools/FinancialModelingPrep/FmpCompanyNewsTool.php`
  (uses a paid API client instead of raw RSS, but same tool shape/attribute/contract).
- There is a **Neuron-agent** tools tree too
  (`src/Domains/Intelligence/Agents/Neuron/Tools/{Area}/*.php`, documented in
  `src/Domains/Intelligence/Agents/CLAUDE.md`) used by "Neuron"-backed agents
  (`NeuronAI\Tools\Tool` base class) — a different LLM runtime than "Laravel" agents but the
  same `#[AgentTool]` discovery/catalog mechanism. A DR-news tool could be written for
  either runtime; Laravel-tools are simpler and match `GoogleNewsRssTool` 1:1.

### 1.2 Pattern B — Connector domain (structured external scraping/import → Kanvas models)

Used when scraped data must be persisted as first-class Kanvas records (e.g. inventory
products), not just handed to an LLM at chat time.

- **Location root:** `src/Domains/Connectors/{ConnectorName}/` — ~80 connectors already
  exist (Shopify, WooCommerce, WordPress, Ghost, SuperCarros, ScrapingDog, ScrapperApi,
  Tavily, Google, ...).
- **Typical sub-folders** (not all connectors have every one):
  - `Client.php` / `AjaxClient.php` — thin HTTP client wrapping the external source. Uses
    `Illuminate\Support\Facades\Http` (with retry/backoff, see
    `Connectors/WordPress/AjaxClient.php`) or Guzzle directly
    (`Connectors/SuperCarros/Client.php`).
  - `Repositories/` — higher-level read methods over the client
    (`Connectors/ScrapingDog/Repositories/ScrapingDogRepository.php`,
    `Connectors/ScrapperApi/Repositories/ScrapperRepository.php`).
  - `Actions/` — single-purpose invokable classes doing the import/mapping/creation work
    (`ScraperAction`, `ScraperProcessorAction`, `ScraperProductAction` in
    `Connectors/ScrapingDog/Actions/`).
  - `Services/` — mapping helpers, e.g. `ProductService::mapProduct()` /
    `ProductVariantService::mapVariant()` that translate the external payload shape into
    Kanvas's importer DTO shape.
  - `Jobs/` — queued work (`Connectors/ScrapingDog/Jobs/ScraperJob.php`,
    `Connectors/ScrapperApi/Jobs/ScrapperJob.php`).
  - `Enums/ConfigEnum.php` — string-backed enum of per-app/per-company config keys read via
    `$app->get(ConfigEnum::X->value)` / `$company->get(...)`.
  - `Handlers/{Name}Handler.php extends BaseIntegration` — `setup()` validates + persists
    connector credentials for a company (see `Connectors/SuperCarros/Handlers/SuperCarrosHandler.php`).
  - `Workflows/Activities/` — Temporal-style workflow activities for async orchestration.
  - `Events/` — domain events fired mid-import (`Connectors/ScrapperApi/Events/ProductScrapperEvent.php`).
- **Console command entry point:** `app/Console/Commands/Connectors/{ConnectorName}/*.php`
  extends `Illuminate\Console\Command` and typically takes `app_id`, `company_id`,
  `userId`, `region_id` as positional arguments plus `--options` for tuning (see
  `app/Console/Commands/Connectors/ScrapingDog/ScrapeScrapingDogBestSellersCommand.php` —
  the canonical "scrape an external site department-by-department and import" example: it
  resolves tenant models, calls a `Repository`, maps results via a `Service`, imports via
  `Kanvas\Inventory\Importer\Actions\ProductImporterAction`, tags/dedupes, and prints a
  summary via `$this->info(...)`).
- **Scheduling:** commands are wired in `app/Console/Kernel.php::schedule()`, either inline
  or via a per-domain `App\Console\Schedules\{Domain}Schedule::register($schedule)` class
  once a domain has 3+ scheduled entries (see `NervousSystemSchedule`, `LeadFollowUpSchedule`,
  `ScribeSchedule`).
- **Web-scraping-without-an-API example:** `Connectors/WordPress/AjaxClient.php` — `Http`
  facade with retry/backoff (`RETRY_DELAY_429_MS`, `RETRY_DELAY_TIMEOUT_MS`), a custom
  `User-Agent`/`Referer`, and `preg_match_all()` HTML-card parsing. Confirms the repo's
  established idiom for scraping a WordPress-based site by hand when no clean API/feed
  exists — **not needed for Nuevo Diario**, which exposes real RSS (§4), but useful if a
  future source has no feed.

### 1.3 Pattern C — Knowledge Source (RAG ingestion for retrieval by any agent)

Used when scraped content should be **chunked, embedded, and made semantically
searchable** for agents, rather than fetched live per-chat-turn.

- **Contract:** `src/Domains/Intelligence/Knowledge/Contracts/KnowledgeSource.php` —
  `entityType(): class-string<Model>` + `build(Model $entity): list<KnowledgeDocument>`.
- **Registry:** `src/Domains/Intelligence/Knowledge/Services/KnowledgeSourceRegistry.php` —
  maps an `entityType` (and a lowercase alias) to its `KnowledgeSource` instance;
  constructor-injected list, defaulting to `[new LeadKnowledgeSource()]`. A new source is
  added to this array (or its Laravel-container binding) to participate.
- **Reference implementation:** `src/Domains/Intelligence/Knowledge/Sources/LeadKnowledgeSource.php`
  — builds one or more `KnowledgeDocument` DTOs (`id`, `content`, `metadata[]`) per model
  instance; `LedgerKnowledgeSource.php` is the second example.
- **DTO:** `Knowledge/DataTransferObject/KnowledgeDocument.php`.
- **Indexing pipeline:** `Knowledge/Services/KnowledgeIndexer.php` →
  `Knowledge/Support/KnowledgeChunker.php` →
  `Knowledge/Embedders/LaravelAiKnowledgeEmbedder.php` →
  `Knowledge/VectorStores/TypesenseKnowledgeStore.php`, triggered by
  `Knowledge/Events/KnowledgeIndexRequested.php` /
  `Agents/Neuron/RAG/Jobs/IndexKnowledgeJob.php` /
  `Agents/Neuron/RAG/Listeners/QueueKnowledgeIndexListener.php`.
- This pattern assumes an **Eloquent model already exists** for the source entity (Lead,
  ledger entry, ...). There is currently **no generic "Article"/"NewsItem" Eloquent model**
  in the codebase, so adopting this pattern for news would require a new lightweight model
  + migration first (see §3, Phase 2) — more work than Pattern A, but it lets *any* agent
  recall a Nuevo Diario headline days later via semantic search instead of only at the
  moment a tool is called.

### 1.4 Agent/tool registration & catalog mechanics (cross-cutting)

- **Discovery attribute:** `src/Domains/Intelligence/Agents/Attributes/AgentTool.php` —
  `#[Attribute(Attribute::TARGET_CLASS)]`, optional `name`/`description`/`category`/
  `frameworks`/`toolType`/`requiresPermission`. Framework and category are derived from the
  namespace when omitted (`Laravel/Tools/News/...` → framework `laravel`? see
  `AgentToolDiscoveryService::frameworkFromNamespace()` — actually derives from the
  `Laravel\`/`Neuron\` path segment; category derives from the folder name, e.g. `News`).
- **Discovery service:** `Agents/Services/AgentToolDiscoveryService.php` extends
  `Baka\Discovery\AttributeClassDiscovery` — scans `src/` and `app/` for any concrete class
  implementing `KanvasToolInterface`, subclassing `NeuronAI\Tools\Tool`, or subclassing
  `KanvasAgentAsTool`, and requires the `#[AgentTool]` attribute unless the constructor has
  required params (dynamic, non-catalogable tools are exempt).
- **Sync command:** `app/Console/Commands/NervousSystem/Tools/SyncAgentToolsCommand.php`
  (`kanvas:nervous-system:sync-tools`) writes discovered tools into the
  `nervous_system_tools` catalog table so they can be granted to agents
  (`NervousSystem/Capability/Models/AgentTool.php`,
  `NervousSystem/Capability/Actions/GrantToolToAgentAction.php`).
- **Models involved for agents generally** (not modified by this plan, only relevant
  context): `Intelligence/Agents/Models/Agent.php`, `AgentType.php`, `AgentLlmConfig.php`,
  `AgentConversation*`, and the capability-side `NervousSystem/Capability/Models/{AgentTool,AgentSkill}.php`.
- **Conventions doc:** `src/Domains/Intelligence/Agents/CLAUDE.md` — read this before writing
  any new tool; it documents the required `[status/message]` return shape, the
  resolve-or-error idiom (`Resolves*ForTool` traits), tenant scoping
  (`->fromApp($this->app)->fromCompany($this->company)`), the "destination safety" rule for
  any outbound tool, and the `TrackByInputs` run-budget rule for per-item tools. A read-only
  RSS-fetch tool (like the one proposed here) is low-risk against most of these rules (no
  outbound send, no tenant-scoped write) but should still: (a) never trust
  attacker-controlled/user-supplied URLs — the feed URL should be **hardcoded** to
  `elnuevodiario.com.do`, not passed in by the LLM, and (b) still go through
  `SafeUrlFetcher` defensively even though the URL is fixed today, matching the existing
  `GoogleNewsRssTool` idiom.

---

## 2. Naming & scope decision for "Nuevo Diario"

Before writing code, pick one of these framings (they are not mutually exclusive — Phase 1
below is a strict subset of Phase 2):

1. **A tool** any existing agent (Sales, Receptionist, CompanyBrain, a future "Dominican
   News" agent, etc.) can call to fetch recent El Nuevo Diario headlines — mirrors
   `GoogleNewsRssTool` almost exactly. **Recommended starting point.**
2. **A scheduled ingestion job** that periodically pulls the feed(s) and stores articles as
   `KnowledgeDocument`s so agents can *semantically recall* older headlines without a live
   fetch (Pattern C). Larger lift (needs a new model), proposed as Phase 2/optional.
3. A brand-new standalone **Neuron/Laravel agent whose entire persona is "the Nuevo Diario
   news desk"** — not recommended as the first step; a tool is reusable by every existing
   agent, whereas a dedicated agent only helps if there's a chat surface specifically for
   DR news. Can be layered on top of (1) later (e.g. a `NuevoDiarioAgent` that composes the
   tool + a "Dominican current-events assistant" persona) with no rework.

This plan implements **(1) as Phase 1** (mirrors an existing, tested pattern almost 1:1,
lowest risk) and documents **(2) as Phase 2 (optional/follow-up)**.

---

## 3. Step-by-step implementation guide

### Phase 1 — `NuevoDiarioRssTool` (agent tool, Laravel-AI runtime)

| # | File | Action |
|---|------|--------|
| 1 | `src/Domains/Intelligence/Agents/Laravel/Tools/News/NuevoDiarioRssTool.php` | **Create.** New class implementing `KanvasToolInterface`, using `HasKanvasContext`, tagged `#[AgentTool(name: 'Nuevo Diario RSS', category: 'knowledge')]`. |
| 2 | `tests/Intelligence/Agents/Tools/NuevoDiarioRssToolTest.php` | **Create.** Mirror `GoogleNewsRssToolTest.php` structure/style (see below). |
| 3 | `tests/Intelligence/NervousSystem/AgentToolCoverageTest.php` and/or `SyncAgentToolsCommandTest.php` | **Verify only** — these guardrail tests assert every catalogable tool carries the attribute / stays in sync with the catalog. Run them after adding the new class; no edits expected unless they hard-code a tool count. |
| 4 | `src/Domains/Intelligence/Agents/CLAUDE.md` | **Optional edit** — add one line under "Pointers to deeper context" or the tools list if the maintainers want every news-source tool cross-referenced (not required for functionality). |

#### 3.1 `NuevoDiarioRssTool` — design

```php
<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\News;

use Baka\Http\Exceptions\SsrfException;
use Baka\Http\SafeUrlFetcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Laravel\Ai\Tools\Request;
use Override;
use SimpleXMLElement;
use Stringable;
use Throwable;

#[AgentTool(name: 'Nuevo Diario RSS', category: 'knowledge')]
class NuevoDiarioRssTool implements KanvasToolInterface
{
    use HasKanvasContext;

    /** Root feed — all articles, every section. */
    private const string BASE_FEED_URL = 'https://elnuevodiario.com.do/feed/';

    /**
     * Known section slugs → their own /{slug}/feed/ (confirmed 200 OK at time of writing).
     * Keep this allowlist — do NOT let the LLM pass an arbitrary path; only ever
     * interpolate a value already present in this map.
     */
    private const array CATEGORY_FEEDS = [
        'nacionales' => 'https://elnuevodiario.com.do/nacionales/feed/',
        'economia' => 'https://elnuevodiario.com.do/economia/feed/',
        'deportes' => 'https://elnuevodiario.com.do/deportes/feed/',
        'internacionales' => 'https://elnuevodiario.com.do/internacionales/feed/',
        'opinion' => 'https://elnuevodiario.com.do/opinion/feed/',
        'tecnologia' => 'https://elnuevodiario.com.do/tecnologia/feed/',
    ];

    public function name(): string
    {
        return Str::slug(AgentTool::fromClass($this)?->name ?? class_basename($this), '_');
    }

    public function instructions(): string
    {
        $name = AgentTool::fromClass($this)?->name ?? $this->name();

        return "Use `{$name}` to fetch recent headlines from El Nuevo Diario (Dominican Republic newspaper). "
            . 'Optionally pass a `category` (one of: ' . implode(', ', array_keys(self::CATEGORY_FEEDS)) . ') '
            . 'to scope to a section, and an optional `limit` (default 10, max 30). '
            . 'Returns an array of articles with title, url, category, author, and publication date.';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Fetch the latest Dominican Republic news headlines from El Nuevo Diario (elnuevodiario.com.do) via its public RSS feed.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $category = strtolower(trim((string) $request->string('category', '')));
        $limit = max(1, min(30, (int) ($request['limit'] ?? 10)));

        $url = $category !== '' && isset(self::CATEGORY_FEEDS[$category])
            ? self::CATEGORY_FEEDS[$category]
            : self::BASE_FEED_URL;

        try {
            $xml = SafeUrlFetcher::fetch($url);
        } catch (SsrfException $e) {
            return json_encode(['error' => 'SSRF protection blocked the request: ' . $e->getMessage()]);
        } catch (Throwable $e) {
            return json_encode(['error' => 'Failed to fetch Nuevo Diario RSS: ' . $e->getMessage()]);
        }

        try {
            $feed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA);
        } catch (Throwable $e) {
            return json_encode(['error' => 'Failed to parse RSS feed: ' . $e->getMessage()]);
        }

        if ($feed === false || ! isset($feed->channel->item)) {
            return json_encode(['articles' => []]);
        }

        $dc = $feed->channel->item[0]->children('http://purl.org/dc/elements/1.1/') ?? null; // primes namespace lookups below

        $articles = [];
        $count = 0;

        foreach ($feed->channel->item as $item) {
            if ($count >= $limit) {
                break;
            }

            $creator = $item->children('http://purl.org/dc/elements/1.1/');

            $articles[] = [
                'title' => (string) $item->title,
                'url' => (string) $item->link,
                'author' => (string) ($creator->creator ?? ''),
                'category' => (string) ($item->category ?? ''),
                'published_at' => (string) $item->pubDate,
            ];

            $count++;
        }

        return json_encode(['articles' => $articles]);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema
                ->string()
                ->description('Optional section to scope to: ' . implode(', ', array_keys(self::CATEGORY_FEEDS)) . '. Leave empty for the front-page feed.'),
            'limit' => $schema
                ->integer()
                ->description('Maximum number of articles to return. Default is 10, max is 30.'),
        ];
    }
}
```

Notes for whoever implements this:

- The `category` param is validated against a **fixed allowlist** (`CATEGORY_FEEDS`) before
  it is ever concatenated into a URL — this is the same defensive posture
  `GoogleNewsRssTool` doesn't need (it URL-encodes free text into a Google-owned query
  param) but matters here because we're choosing between our own **path segments**.
  Never do `"https://elnuevodiario.com.do/{$category}/feed/"` with an unchecked `$category`.
  Confirmed 200-OK slugs at research time: `nacionales`, `economia`, `deportes`,
  `internacionales`, `opinion`, `tecnologia` (see §4.3) — `entretenimiento` 404'd and was
  excluded; re-verify the full section list periodically, the site's taxonomy can change.
- Reuse `SafeUrlFetcher` even though the URL isn't LLM-supplied — defense in depth, and it
  gets the byte cap / timeout / redirect protection for free.
- Keep the `simplexml_load_string(..., LIBXML_NOCDATA)` idiom — required because
  `description`/`content:encoded` are CDATA-wrapped HTML (confirmed in §4.1 sample).
- `dc:creator` is in the `http://purl.org/dc/elements/1.1/` namespace — must be read via
  `$item->children('http://purl.org/dc/elements/1.1/')->creator`, not `$item->creator`
  directly (SimpleXML namespace rule — the same reason `GoogleNewsRssTool` didn't need this
  is that Google's `<source>` tag is unprefixed).
- Return shape mirrors `GoogleNewsRssTool` (`title`, `url`, `published_at`, plus this feed's
  extra `author`/`category` fields) so any prompt referencing "the news tool" generalizes.

#### 3.2 Test — `NuevoDiarioRssToolTest`

Mirror `tests/Intelligence/Agents/Tools/GoogleNewsRssToolTest.php` 1:1 in structure:

```php
final class NuevoDiarioRssToolTest extends TestCase
{
    use DatabaseTransactions;

    private Apps $kanvasApp;

    protected function setUp(): void { parent::setUp(); $this->kanvasApp = app(Apps::class); }

    private function makeTool(): NuevoDiarioRssTool
    {
        $company = auth()->user()->getCurrentCompany();
        return (new NuevoDiarioRssTool())->withContext($this->kanvasApp, $company);
    }

    public function testToolHasNameAndDescription(): void { /* asserts name === 'nuevo_diario_rss' */ }
    public function testHandleReturnsArticles(): void { /* markTestSkipped on ['error'] like the Google test */ }
    public function testHandleRespectsLimit(): void { /* ... */ }
    public function testHandleFiltersByCategory(): void { /* assert every returned article's category matches (best-effort — RSS category text isn't guaranteed to equal the slug) or just assert no error + articles shape */ }
    public function testHandleRejectsUnknownCategoryFallsBackToFrontPage(): void { /* category=bogus → same as no category */ }
}
```

Follow the "network may be unreachable in CI" skip pattern already used — **do not** make
the suite fail hard on a live network blip; that's the established convention here, not a
gap to fix.

#### 3.3 Rollout checklist

1. Create the tool class + test above.
2. Run the tool's own test: `php vendor/bin/phpunit tests/Intelligence/Agents/Tools/NuevoDiarioRssToolTest.php` (inside the project's Docker container per `tests/CLAUDE.md`).
3. Run the discovery/catalog guardrail tests to confirm the new class is picked up cleanly:
   `tests/Intelligence/NervousSystem/SyncAgentToolsCommandTest.php`,
   `tests/Intelligence/NervousSystem/AgentToolCoverageTest.php`.
4. Optionally run `php artisan kanvas:nervous-system:sync-tools` locally to confirm the tool
   lands in the `nervous_system_tools` catalog with category `knowledge` before it's granted
   to any agent via `GrantToolToAgentAction`.
5. Grant the tool to whichever agent(s) should use it (e.g. a Dominican-market
   `CompanyBrainAgent`/`SalesAgent` instance, or a future dedicated news-desk agent) — this
   is a data change (agent ↔ tool grant), not a code change, so it happens per-tenant via
   existing capability tooling, not in this PR.

### Phase 2 (optional/follow-up) — scheduled ingestion into Knowledge/RAG

If the goal grows from "an agent can look this up when asked" to "agents should recall
older Nuevo Diario coverage automatically / semantically", extend with:

1. **New lightweight model + migration** — e.g. `Kanvas\Connectors\NuevoDiario\Models\NewsArticle`
   (or a generic cross-source `Kanvas\Content\NewsArticle` if more sources are planned) with
   columns: `apps_id`, `source` (`nuevo_diario`), `external_id` (RSS `guid`), `title`, `url`
   (unique per source to dedupe), `category`, `author`, `summary`, `published_at`,
   `created_at`/`updated_at`. Follow the existing multi-DB convention — pick the connection
   this domain should live on (likely `social` or a new `content` connection; confirm with
   whoever owns `config/database.php`'s connection map before deciding).
2. **Connector skeleton** at `src/Domains/Connectors/NuevoDiario/` following Pattern B
   (§1.2):
   - `Client.php` — wraps `SafeUrlFetcher` + the RSS parsing already written for the tool
     (extract the parsing logic to a shared `Services/FeedParserService.php` so the Phase-1
     tool and the Phase-2 ingester don't duplicate the SimpleXML logic — the tool can
     delegate to this service instead of inlining it, see refactor note below).
   - `Enums/ConfigEnum.php` — `FEED_URL`, `CATEGORIES` (per-app configurable, in case a
     tenant wants a subset of sections ingested).
   - `Actions/ImportNuevoDiarioArticlesAction.php` — fetch → dedupe by `url`/`guid` → upsert
     `NewsArticle` rows → dispatch knowledge indexing for each new row.
   - `Jobs/ImportNuevoDiarioArticlesJob.php` — queued wrapper for the action.
3. **Knowledge source** — `src/Domains/Intelligence/Knowledge/Sources/NewsArticleKnowledgeSource.php`
   implementing `KnowledgeSource` (Pattern C, §1.3): `entityType()` → `NewsArticle::class`;
   `build(Model $entity)` → one `KnowledgeDocument` per article (`content` = title + summary
   + stripped `content:encoded`, `metadata` = source/category/published_at/url). Register it
   in `KnowledgeSourceRegistry`'s source list.
4. **Command + schedule** —
   `app/Console/Commands/Connectors/NuevoDiario/ImportNuevoDiarioArticlesCommand.php`
   (`kanvas:nuevodiario-import {app_id} {--categories=}`), wired hourly (the feed's own
   `<sy:updateFrequency>` says hourly, see §4.1) in `app/Console/Kernel.php::schedule()` —
   or its own `App\Console\Schedules\NuevoDiarioSchedule` once there's more than a couple of
   entries, matching the existing per-domain-schedule-class convention.
5. **Refactor note:** once Phase 2 exists, consider whether `NuevoDiarioRssTool::handle()`
   should call the same `FeedParserService` used by the ingestion `Action`, so "live lookup"
   and "background ingestion" can't silently drift in how they parse the feed.

This phase is **not required** to satisfy "an agent that can answer questions about current
Dominican news" — Phase 1 alone does that. Only build Phase 2 if there's an explicit product
need for historical semantic recall across many articles/sessions.

---

## 4. El Nuevo Diario — source details (RSS)

`elnuevodiario.com.do` runs on **WordPress** and exposes standard WordPress RSS2 feeds — no
scraping (HTML parsing) is required, unlike the `WordPress/AjaxClient.php` inventory
connector which had no feed to rely on.

### 4.1 Primary feed

```
https://elnuevodiario.com.do/feed/
```

Confirmed reachable (verified live during this research, `HTTP 200`). Sample structure
(namespaces trimmed for brevity):

```xml
<rss version="2.0" xmlns:content="..." xmlns:dc="..." xmlns:atom="...">
<channel>
  <title>El Nuevo Diario (República Dominicana)</title>
  <atom:link href="https://elnuevodiario.com.do/feed/" rel="self" type="application/rss+xml" />
  <description>Noticias República Dominicana</description>
  <lastBuildDate>Fri, 14 Aug 2026 03:36:09 +0000</lastBuildDate>
  <language>es</language>
  <sy:updatePeriod>hourly</sy:updatePeriod>
  <sy:updateFrequency>1</sy:updateFrequency>
  <item>
    <title>Educación y orden</title>
    <link>https://elnuevodiario.com.do/educacion-y-orden/</link>
    <dc:creator><![CDATA[Persio Maldonado]]></dc:creator>
    <pubDate>Fri, 14 Aug 2026 04:01:54 +0000</pubDate>
    <category><![CDATA[Editorial]]></category>
    <guid isPermaLink="false">https://elnuevodiario.com.do/?p=3237675</guid>
    <description><![CDATA[<img ... /><p>Hay dos factores transversales ... [&#8230;]</p> ...]]></description>
    <content:encoded><![CDATA[<img ... /><p>Hay dos factores ... (full HTML article body)</p>]]></content:encoded>
  </item>
  <!-- more <item> entries -->
</channel>
</rss>
```

Field notes:
- `description` is a **CDATA-wrapped HTML excerpt** (a lead image `<img>` + a truncated
  `<p>` ending in `[…]`) — good for a short summary once `strip_tags()`'d.
- `content:encoded` (namespace `http://purl.org/rss/1.0/modules/content/`) carries the
  **full article HTML body** — useful if Phase 2 wants richer knowledge documents than the
  excerpt, but note it's the full article text (copyright-sensitive — only use for internal
  agent context, not for republishing verbatim).
- `dc:creator` (namespace `http://purl.org/dc/elements/1.1/`) is the byline/author.
- `category` may repeat (multiple `<category>` tags per item) — the parsing code above only
  reads the first; extend to `foreach ($item->category as $c)` if multiple tags matter.
- `guid` is `isPermaLink="false"` and is the WordPress post ID URL
  (`?p=<id>`) — good as a stable dedupe key for Phase 2, in addition to `link`.
- The feed updates roughly hourly (`sy:updateFrequency`/`updatePeriod`) — informs the Phase
  2 schedule cadence.

### 4.2 Section/category feeds

WordPress exposes a feed per category/section automatically at
`https://elnuevodiario.com.do/{section-slug}/feed/`. Confirmed **HTTP 200** at research time
for:

| Slug | URL |
|---|---|
| `nacionales` | `https://elnuevodiario.com.do/nacionales/feed/` |
| `economia` | `https://elnuevodiario.com.do/economia/feed/` |
| `deportes` | `https://elnuevodiario.com.do/deportes/feed/` |
| `internacionales` | `https://elnuevodiario.com.do/internacionales/feed/` |
| `opinion` | `https://elnuevodiario.com.do/opinion/feed/` |
| `tecnologia` | `https://elnuevodiario.com.do/tecnologia/feed/` |

`entretenimiento` returned `404` at research time — **do not assume a slug exists**;
whoever finalizes the section list should re-verify against the live site (or its sitemap)
before hardcoding the allowlist, since WordPress category slugs are editorial and can be
renamed/merged.

### 4.3 Fetching guidance

- Always send a normal browser-like `User-Agent` (some DR news sites rate-limit or block the
  default PHP/Guzzle UA) — `SafeUrlFetcher` uses Guzzle defaults today; if requests start
  getting blocked, add a `User-Agent` header the same way
  `Connectors/WordPress/AjaxClient.php` does, rather than bypassing `SafeUrlFetcher`.
- No API key or authentication is required — it's a public RSS feed.
- Respect `sy:updateFrequency` (hourly) for any scheduled ingestion — polling much more
  often than that is unnecessary load on the source and won't surface new content faster.
- There is no official rate limit published; be a good citizen (hourly cron, not per-request
  polling from every tenant).

---

## 5. Open questions for whoever picks this up

1. **Which agents should get this tool by default?** (none automatically — needs an
   explicit `GrantToolToAgentAction` per agent/tenant, per §3.3 step 5.)
2. **Is Phase 2 (persisted articles + RAG) actually needed**, or is "ask the agent and it
   fetches live" sufficient for the product requirement that prompted this? Recommend
   shipping Phase 1 first and revisiting.
3. **Section allowlist ownership** — should `CATEGORY_FEEDS` live as a hardcoded const (as
   proposed) or as an app-level config value (`$app->get(...)`) so ops can adjust it without
   a deploy? Hardcoded is simpler and matches `GoogleNewsRssTool`'s own lack of
   per-app config; only move it to config if multiple tenants need different sections.
4. **Attribution/licensing** — `content:encoded` is full copyrighted article text. Confirm
   with product/legal whether full-text ingestion (Phase 2) is acceptable for internal agent
   context vs. excerpt-only (`description`) before implementing Phase 2.
