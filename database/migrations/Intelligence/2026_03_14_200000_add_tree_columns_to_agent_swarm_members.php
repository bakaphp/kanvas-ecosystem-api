<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->table('agent_swarm_members', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_id')->nullable()->after('agent_id');
            $table->string('path')->nullable()->after('parent_id');

            $table->foreign('parent_id')->references('id')->on('agent_swarm_members');
            $table->index('path');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('agent_swarm_members', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['path']);
            $table->dropColumn(['parent_id', 'path']);
        });
    }
};
