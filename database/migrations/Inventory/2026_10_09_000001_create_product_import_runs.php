<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A run groups every importProduct batch a client sends between its first call and
 * finishProductImport. Batches are stamped as "seen" when the mutation receives them, not when
 * the queued job runs, so the sweep at finish never has to wait for the queue.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('product_import_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->unsignedInteger('users_id');
            $table->unsignedBigInteger('channels_id')->nullable();
            $table->string('status', 32);
            $table->unsignedInteger('batches_count')->default(0);
            $table->unsignedInteger('skus_count')->default(0);
            $table->unsignedInteger('published_count')->default(0);
            $table->unsignedInteger('unpublished_count')->default(0);
            $table->string('skipped_reason')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('last_activity_at');
            $table->timestamp('finished_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->index(['apps_id', 'companies_id', 'status'], 'pir_tenant_status_index');
        });

        // Nullable + no default so MySQL adds the column instantly; the index build is online
        // (no write lock) but still reads the whole table.
        Schema::table('products_variants_channels', function (Blueprint $table) {
            $table->unsignedBigInteger('last_import_run_id')->index()->nullable();
            $table->index(['channels_id', 'last_import_run_id'], 'pvc_channel_import_run_index');
            // Covers FinishProductImportAction's sweep end to end, so it stays index-only as a
            // channel grows instead of one row lookup per published variant.
            $table->index(
                ['channels_id', 'is_published', 'is_deleted', 'created_at', 'last_import_run_id', 'products_variants_id'],
                'pvc_import_sweep_covering_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('products_variants_channels', function (Blueprint $table) {
            $table->dropIndex('pvc_import_sweep_covering_index');
            $table->dropIndex('pvc_channel_import_run_index');
            $table->dropColumn('last_import_run_id');
        });

        Schema::dropIfExists('product_import_runs');
    }
};
