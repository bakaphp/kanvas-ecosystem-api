<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    private const CONNECTION = 'intelligence';

    public function up(): void
    {
        // A plan can be assigned to a HUMAN member, not just an agent — the point of a project is a
        // mixed team. agent_id holds an agent assignee (auto-executes); assigned_users_id holds a
        // human assignee (notified + tracked, done manually). At most one is set.
        Schema::connection(self::CONNECTION)->table('nervous_system_plans', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_users_id')->nullable()->after('agent_id')->index();
        });
    }

    public function down(): void
    {
        Schema::connection(self::CONNECTION)->table('nervous_system_plans', function (Blueprint $table) {
            $table->dropColumn('assigned_users_id');
        });
    }
};
