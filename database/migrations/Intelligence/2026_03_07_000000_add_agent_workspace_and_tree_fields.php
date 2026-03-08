<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Workspace fields (OpenClaw-standard)
            $table->longText('soul')->nullable()->after('role');
            $table->longText('instructions')->nullable()->after('soul');
            $table->longText('output_format')->nullable()->after('instructions');
            $table->json('identity')->nullable()->after('output_format');
            $table->longText('user_context')->nullable()->after('identity');
            $table->longText('tools_config')->nullable()->after('user_context');
            $table->string('deployment_status')->default('pending')->index()->after('is_active');

            // Tree structure (nevadskiy/laravel-tree materialized path)
            $table->unsignedBigInteger('parent_id')->nullable()->after('agent_type_id')->index();
            $table->string('path')->nullable()->after('parent_id')->index();
            $table->foreign('parent_id')->references('id')->on('agents')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn([
                'soul',
                'instructions',
                'output_format',
                'identity',
                'user_context',
                'tools_config',
                'deployment_status',
                'parent_id',
                'path',
            ]);
        });
    }
};
