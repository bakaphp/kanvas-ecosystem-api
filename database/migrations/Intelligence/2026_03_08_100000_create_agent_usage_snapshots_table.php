<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('agent_usage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->index();
            $table->unsignedBigInteger('apps_id')->index();
            $table->unsignedBigInteger('companies_id')->index();
            $table->date('snapshot_date')->index();
            $table->string('source', 50)->default('openclaw')->index();
            $table->longText('raw_output');
            $table->json('parsed_data')->nullable();
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_deleted')->default(0)->index();

            $table->unique(
                ['apps_id', 'companies_id', 'snapshot_date', 'source'],
                'idx_usage_snapshot_unique'
            );
            $table->index(
                ['apps_id', 'companies_id', 'snapshot_date'],
                'idx_usage_snapshot_search'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_usage_snapshots');
    }
};
