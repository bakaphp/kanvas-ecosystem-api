<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An MCP catalog row points at the `integrations` row that describes its server, the way a SUB_AGENT
 * row points at `agents_id`. No FK: `integrations` lives on the `workflow` connection.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->table('nervous_system_tools', function (Blueprint $table): void {
            $table->unsignedBigInteger('integrations_id')->nullable()->after('handler');
            $table->index('integrations_id', 'nervous_system_tools_integrations_id_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_tools', function (Blueprint $table): void {
            $table->dropIndex('nervous_system_tools_integrations_id_idx');
            $table->dropColumn('integrations_id');
        });
    }
};
