<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->create('nervous_system_agent_selected_tools', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('tool_id');
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['agent_id', 'tool_id']);
            $table->index('tool_id');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->dropIfExists('nervous_system_agent_selected_tools');
    }
};
