<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * MCP catalog rows were named after their integration slug (`tavily_mcp`), which is what an admin reads in
 * the tool list and what an agent says when it asks for a capability by name. Every other tool in the
 * catalog carries a human title ("Read Google Sheet"), so these now do too.
 *
 * Only the catalog row's title changes. `integrations.name` stays the slug: it is the identifier code
 * looks rows up by (`GoogleSheets\Client::forAgent` finds `google_sheets_mcp`), the frontend filters on
 * it, and the MCP docs reference it. Nothing matches a TOOL row by that slug — `McpConnectionService`
 * resolves tools through `integrations_id` — so the rename is safe.
 *
 * The insert migrations that created these rows are left as they were: this is the one place the titles
 * are decided, for a fresh database and an existing one alike.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    /**
     * Integration slug => the title an admin and an agent should read.
     *
     * Google Calendar carries a suffix because a native tool already holds that exact name, and
     * ToolGrantResolver matches a catalog row by its label — two rows answering to "Google Calendar"
     * would make every grant of it ambiguous. Nothing else in the catalog collides.
     */
    private const array TITLES = [
        'atlassian_mcp' => 'Atlassian',
        'github_mcp' => 'GitHub',
        'linear_mcp' => 'Linear',
        'n8n_mcp' => 'n8n',
        'google_gmail_mcp' => 'Gmail',
        'google_drive_mcp' => 'Google Drive',
        'google_calendar_mcp' => 'Google Calendar (MCP)',
        'google_sheets_mcp' => 'Google Sheets',
        'google_people_mcp' => 'Google People',
        'notion_mcp' => 'Notion',
        'sentry_mcp' => 'Sentry',
        'stripe_mcp' => 'Stripe',
        'figma_mcp' => 'Figma',
        'browserbase_mcp' => 'Browserbase',
        'playwright_mcp' => 'Playwright',
        'tavily_mcp' => 'Tavily',
    ];

    public function up(): void
    {
        foreach (self::TITLES as $slug => $title) {
            $this->rename(from: $slug, to: $title);
        }
    }

    public function down(): void
    {
        foreach (self::TITLES as $slug => $title) {
            $this->rename(from: $title, to: $slug);
        }
    }

    private function rename(string $from, string $to): void
    {
        DB::connection('intelligence')->table('nervous_system_tools')
            ->where('name', $from)
            ->where('tool_type', ToolTypeEnum::MCP->value)
            ->where('apps_id', 0)
            ->update(['name' => $to, 'updated_at' => now()]);
    }
};
