<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->enum('awake_state', ['awake', 'sleeping', 'offline'])
                ->default('offline')
                ->after('is_active');
            $table->timestamp('last_state_changed_at')->nullable()->after('awake_state');
            $table->index('awake_state', 'idx_agents_awake_state');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropIndex('idx_agents_awake_state');
            $table->dropColumn(['awake_state', 'last_state_changed_at']);
        });
    }
};
