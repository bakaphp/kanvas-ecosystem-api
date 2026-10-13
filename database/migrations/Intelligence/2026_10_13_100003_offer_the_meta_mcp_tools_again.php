<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * The reversal `2026_09_12_100029` promised: Meta Ads and WhatsApp Business (MCP) come back into the
 * catalog now that their rows connect through a hand-made Meta app or a user access token (workflow
 * migration `2026_10_13_100001`). Meta Ads' description loses its "one click, no developer app" promise,
 * which was true of the registration path Meta then closed, and says how to connect instead.
 */
return new class () extends Migration {
    private const string ADS_TITLE = 'Meta Ads';

    private const string WHATSAPP_TITLE = 'WhatsApp Business (MCP)';

    private const string ADS_DESCRIPTION = 'Meta Ads over MCP — Facebook and Instagram advertising on the '
        . 'connected Business Manager account: reporting and insights, campaign management, catalogue '
        . 'operations, signals and datasets, A/B tests and lift studies, activity logs and account '
        . 'diagnostics. Every campaign, ad set and ad it creates is left PAUSED, so nothing starts spending '
        . 'without a person resuming it. Connect with the Connect button (signs in with the Facebook account '
        . 'through the company\'s Meta app) or paste a user access token that carries the ads MCP permissions.';

    private const string ADS_DESCRIPTION_BEFORE = 'Meta Ads over MCP — Facebook and Instagram advertising on '
        . 'the connected Business Manager account: reporting and insights, campaign management, catalogue '
        . 'operations and account diagnostics. Every campaign, ad set and ad it creates is left PAUSED, so '
        . 'nothing starts spending without a person resuming it. Connecting is one click; Meta needs no '
        . 'developer app.';

    protected $connection = 'intelligence';

    public function up(): void
    {
        $this->tool(self::ADS_TITLE)->update([
            'is_active' => 1,
            'description' => self::ADS_DESCRIPTION,
            'updated_at' => now(),
        ]);

        $this->tool(self::WHATSAPP_TITLE)->update([
            'is_active' => 1,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $this->tool(self::ADS_TITLE)->update([
            'is_active' => 0,
            'description' => self::ADS_DESCRIPTION_BEFORE,
            'updated_at' => now(),
        ]);

        $this->tool(self::WHATSAPP_TITLE)->update([
            'is_active' => 0,
            'updated_at' => now(),
        ]);
    }

    private function tool(string $title): Illuminate\Database\Query\Builder
    {
        return DB::connection('intelligence')->table('nervous_system_tools')
            ->where('name', $title)
            ->where('tool_type', ToolTypeEnum::MCP->value)
            ->where('apps_id', 0);
    }
};
