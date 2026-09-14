<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only log of what discovery showed, in what order, for which query.
 *
 * This is the raw material every later ranking or personalization loop reads,
 * and it cannot be reconstructed after the fact — a query answered today and
 * not recorded is gone. Hence recording it from the first release rather than
 * when the loops get built.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('product_recommendation_impressions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->unsignedInteger('users_id')->nullable();
            $table->string('session_id', 64)->nullable();

            // Returned to the client and sent back on click/purchase — without it
            // an outcome cannot be attributed to the query that produced it.
            $table->uuid('recommendation_uuid');

            $table->text('query_raw');
            $table->string('query_normalized', 255);
            $table->json('intent')->nullable();
            $table->json('product_ids');
            $table->unsignedSmallInteger('results_count')->default(0);
            $table->string('engine', 32)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('recommendation_uuid');
            $table->index(['apps_id', 'companies_id', 'created_at'], 'pri_tenant_created_index');
            // Powers the popular / no-hit query report: the first place to look
            // when deciding whether a miss is a catalog gap or a bad blurb.
            $table->index(['apps_id', 'companies_id', 'query_normalized'], 'pri_tenant_query_index');
            $table->index(['apps_id', 'companies_id', 'users_id'], 'pri_tenant_user_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_recommendation_impressions');
    }
};
