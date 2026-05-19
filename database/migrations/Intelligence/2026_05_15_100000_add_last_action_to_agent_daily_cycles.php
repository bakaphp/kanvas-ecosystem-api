<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::table('agent_daily_cycles', function (Blueprint $table) {
            $table->timestamp('last_action_at')->nullable()->after('events_processed_count');
            $table->string('last_action_label', 500)->nullable()->after('last_action_at');
        });
    }

    public function down(): void
    {
        Schema::table('agent_daily_cycles', function (Blueprint $table) {
            $table->dropColumn(['last_action_at', 'last_action_label']);
        });
    }
};
