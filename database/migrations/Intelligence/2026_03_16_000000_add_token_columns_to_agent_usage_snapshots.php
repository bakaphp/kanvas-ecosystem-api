<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->table('agent_usage_snapshots', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_deployment_id')->nullable()->index()->after('companies_id');
            $table->unsignedBigInteger('input_tokens')->default(0)->after('source');
            $table->unsignedBigInteger('output_tokens')->default(0)->after('input_tokens');
            $table->unsignedBigInteger('total_tokens')->default(0)->after('output_tokens');
            $table->unsignedBigInteger('cache_read_tokens')->default(0)->after('total_tokens');
            $table->unsignedBigInteger('cache_write_tokens')->default(0)->after('cache_read_tokens');
            $table->string('provider', 50)->nullable()->index()->after('cache_write_tokens');
            $table->string('model', 100)->nullable()->after('provider');
            $table->unsignedInteger('total_sessions')->default(0)->after('model');

            $table->dropUnique('idx_usage_snapshot_unique');
            $table->unique(
                ['apps_id', 'companies_id', 'agent_deployment_id', 'snapshot_date', 'source'],
                'idx_usage_snapshot_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('agent_usage_snapshots', function (Blueprint $table) {
            $table->dropColumn([
                'agent_deployment_id',
                'input_tokens',
                'output_tokens',
                'total_tokens',
                'cache_read_tokens',
                'cache_write_tokens',
                'provider',
                'model',
                'total_sessions',
            ]);
        });
    }
};
