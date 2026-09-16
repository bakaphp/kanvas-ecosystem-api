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
            $table->string('board_column_key', 80)->nullable()->after('status');
            $table->index(['project_id', 'board_column_key'], 'plan_project_board_column');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_plans', function (Blueprint $table) {
            $table->dropIndex('plan_project_board_column');
            $table->dropColumn('board_column_key');
        });
    }
};
