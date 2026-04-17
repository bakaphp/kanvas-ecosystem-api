<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->table('agent_histories', function (Blueprint $table) {
            $table->unsignedInteger('users_id')->nullable()->index('idx_agent_histories_users_id')->after('apps_id');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('agent_histories', function (Blueprint $table) {
            $table->dropIndex('idx_agent_histories_users_id');
            $table->dropColumn('users_id');
        });
    }
};
