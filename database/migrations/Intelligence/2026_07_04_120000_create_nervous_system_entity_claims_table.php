<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->create('nervous_system_entity_claims', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->string('entity_namespace', 191);
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('agent_id');
            $table->string('reason', 255)->nullable();
            $table->char('correlation_id', 36)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            // One live claim per entity per tenant. Rows are always active: release
            // hard-deletes and acquire clears an expired holder first, so this plain
            // unique index is what makes concurrent acquire atomic (second insert
            // hits the unique violation and the caller defers).
            $table->unique(
                ['apps_id', 'companies_id', 'entity_namespace', 'entity_id'],
                'ns_entity_claim_unique'
            );
            $table->index(['agent_id'], 'agent_idx');
            $table->index(['expires_at'], 'expires_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->dropIfExists('nervous_system_entity_claims');
    }
};
