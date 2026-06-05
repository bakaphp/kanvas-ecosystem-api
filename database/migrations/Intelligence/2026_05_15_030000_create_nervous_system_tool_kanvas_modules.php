<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::create('nervous_system_tool_kanvas_modules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tool_id');
            // Logical reference to ecosystem.kanvas_modules.id — cross-DB FK not enforceable.
            $table->unsignedBigInteger('kanvas_modules_id');
            $table->enum('direction', ['consumes', 'produces', 'both'])->default('consumes');
            $table->timestamps();

            $table->foreign('tool_id')
                ->references('id')
                ->on('nervous_system_tools')
                ->onDelete('cascade');
            $table->unique(['tool_id', 'kanvas_modules_id'], 'uniq_tool_module');
            $table->index('kanvas_modules_id', 'idx_kanvas_module');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nervous_system_tool_kanvas_modules');
    }
};
