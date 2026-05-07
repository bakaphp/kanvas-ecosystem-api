<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table('nervous_system_tools', function (Blueprint $table) {
            $table->dropIndex(['agent_type_id']);
            $table->dropColumn('agent_type_id');
        });

        Schema::connection('intelligence')->create('nervous_system_tool_agent_types', function (Blueprint $table) {
            $table->unsignedBigInteger('tool_id');
            $table->unsignedBigInteger('agent_type_id');
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['tool_id', 'agent_type_id']);
            $table->index('agent_type_id');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->dropIfExists('nervous_system_tool_agent_types');

        Schema::connection('intelligence')->table('nervous_system_tools', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_type_id')->nullable()->after('apps_id')->index();
        });
    }
};
