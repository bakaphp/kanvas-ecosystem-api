<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->table('agent_llm_configs', function (Blueprint $table) {
            $table->boolean('is_routing_enabled')
                ->default(false)
                ->after('is_active')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('agent_llm_configs', function (Blueprint $table) {
            $table->dropColumn('is_routing_enabled');
        });
    }
};
