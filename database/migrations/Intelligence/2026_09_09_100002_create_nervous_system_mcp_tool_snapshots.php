<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The durable tier of the descriptor cache. Redis alone would mean a flush, an eviction or a cold
 * deploy sends every agent turn across every company at the vendor at once — and if the vendor is
 * down at that moment, agents silently hold zero tools. This row is the last known good `tools/list`.
 *
 * NOT a catalog: one row per (server, company) holding the whole payload as a blob. The methods are
 * never grantable entities, so there is no drift/prune problem against a list we do not control.
 *
 * No FKs — `integrations` is on `workflow`, `companies` on `ecosystem`. Same precedent as
 * `nervous_system_tool_kanvas_modules`.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->create('nervous_system_mcp_tool_snapshots', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('integrations_id');
            $table->json('payload');
            $table->char('payload_hash', 40);
            $table->unsignedInteger('tool_count')->default(0);
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->unique(
                ['apps_id', 'companies_id', 'integrations_id'],
                'mcp_tool_snapshots_tenant_integration_unique'
            );
            $table->index('integrations_id', 'mcp_tool_snapshots_integrations_id_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->dropIfExists('nervous_system_mcp_tool_snapshots');
    }
};
