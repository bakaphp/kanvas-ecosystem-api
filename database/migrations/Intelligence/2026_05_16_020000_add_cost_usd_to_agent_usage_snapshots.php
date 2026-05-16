<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::table('agent_usage_snapshots', function (Blueprint $table) {
            $table->decimal('cost_usd', 12, 6)->default(0)->after('total_tokens');
            $table->index(['apps_id', 'companies_id', 'snapshot_date'], 'idx_snapshots_tenant_date');
        });
    }

    public function down(): void
    {
        Schema::table('agent_usage_snapshots', function (Blueprint $table) {
            $table->dropIndex('idx_snapshots_tenant_date');
            $table->dropColumn('cost_usd');
        });
    }
};
