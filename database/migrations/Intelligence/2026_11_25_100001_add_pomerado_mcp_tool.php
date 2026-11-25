<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * Filed under `code` beside the other servers that work a website for the agent (Browser Use, Browserless,
 * Kernel, Playwright).
 */
return new class () extends Migration {
    private const string SLUG = 'pomerado_mcp';

    private const string TITLE = 'Pomerado';

    private const string CATEGORY = 'code';

    private const string DESCRIPTION = 'Does tasks on websites through Pomerado: searching flights, hotels and rentals, '
        . 'comparing prices, checking orders, deliveries, bills and account pages, filling in forms, booking and '
        . 'checking out. Find a ready-made tool by task or site first, then run it; build a new tool only when none '
        . 'fits, and tell the person it is building because a build takes minutes. A tool that only reads a site can '
        . 'be run freely. A tool that changes a site (a booking, an order, a payment, a submitted form) acts on the '
        . 'real website with the connected account\'s saved logins, so state exactly what it will do and get the '
        . 'person\'s confirmation before running it, and never run the same change twice: when a job failed or was '
        . 'cancelled, read its write_status first. A job that is still running is followed in the background and '
        . 'you are woken with its result, so say it has started and end your turn. When a job needs the person (a '
        . 'login, a code, an approval, a step in its browser), give them the job\'s answer_url and end your turn; '
        . 'never ask for a password, a card or an authenticator key in the conversation. A result can be read '
        . 'once, so report it when you receive it. Report what the tools returned; never invent a price, an order '
        . 'or a confirmation they did not. Runs and builds count against the connected Pomerado plan. Connect it '
        . 'with the Connect button, or paste a Pomerado API key (app.pomerado.ai, Settings, API keys).';

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

        $categoryId = DB::connection('intelligence')->table('nervous_system_tool_categories')
            ->where('apps_id', 0)
            ->where('slug', self::CATEGORY)
            ->value('id');

        DB::connection('intelligence')->table('nervous_system_tools')->insert([
            'uuid' => (string) Str::uuid(),
            'apps_id' => 0,
            'name' => self::TITLE,
            'description' => self::DESCRIPTION,
            'tool_type' => ToolTypeEnum::MCP->value,
            'tool_category_id' => $categoryId,
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
