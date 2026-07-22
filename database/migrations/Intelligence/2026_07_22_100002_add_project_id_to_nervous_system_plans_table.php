<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->table('nervous_system_plans', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->after('swarm_id');
            $table->index(['project_id'], 'plan_project_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_plans', function (Blueprint $table) {
            $table->dropIndex('plan_project_idx');
            $table->dropColumn('project_id');
        });
    }
};
