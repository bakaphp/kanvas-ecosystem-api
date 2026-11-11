<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * Titled `Apollo (MCP)` because the native Apollo connector already registers `Apollo LinkedIn Lookup`
 * and the enrichment activity in the catalog; grants resolve by label.
 */
return new class () extends Migration {
    private const string SLUG = 'apollo_mcp';

    private const string TITLE = 'Apollo (MCP)';

    private const string DESCRIPTION = 'Apollo.io over MCP — find prospects by title, seniority, location, industry and company size; '
        . 'enrich a person or company from an email, name, domain or LinkedIn URL; read and update the workspace\'s '
        . 'contacts, accounts, opportunities, tasks and lists; look up sequences and their activity; and pull sales '
        . 'analytics. Enrichment and some searches spend the account\'s Apollo credits, so enrich the records the '
        . 'person actually asked about, not whole result pages. Adding a contact to a sequence starts outbound email '
        . 'from the connected person\'s own mailbox: name the sequence and the contacts and get a yes before you '
        . 'enroll anyone, and never enroll from a search result without that confirmation. Report what Apollo '
        . 'returned; never invent a contact, a company or a verified email the tools did not return. Connect it with '
        . 'the Connect button — it signs in with your Apollo account — or paste an Apollo API key. Any Apollo plan '
        . 'qualifies, including free.';

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
