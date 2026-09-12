<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * Meta's own MCP server cannot be connected from here: it gates client registration to clients it has
 * approved, and its hosted endpoint accepts no hand-made client id either, so Connect can only ever end
 * in "Dynamic registration is not available for this client".
 *
 * The row is deactivated rather than deleted — the server is real and the day Meta opens registration this
 * is a one-line reversal. Until then `Meta Ads (Pipeboard)` is the working route, and offering a server
 * that always fails is worse than offering none.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        $this->setActive(0);
    }

    public function down(): void
    {
        $this->setActive(1);
    }

    private function setActive(int $active): void
    {
        DB::connection('intelligence')->table('nervous_system_tools')
            ->where('name', 'Meta Ads')
            ->where('tool_type', ToolTypeEnum::MCP->value)
            ->where('apps_id', 0)
            ->update(['is_active' => $active, 'updated_at' => now()]);
    }
};
