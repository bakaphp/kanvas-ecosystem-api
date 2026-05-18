<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::create('agents_kanvas_modules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agent_id');
            // Logical reference to ecosystem.kanvas_modules.id — cross-DB FK not enforceable.
            $table->unsignedBigInteger('kanvas_modules_id');
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->foreign('agent_id')
                ->references('id')
                ->on('agents')
                ->onDelete('cascade');
            $table->unique(['agent_id', 'kanvas_modules_id'], 'uniq_agent_module');
            $table->index('kanvas_modules_id', 'idx_kanvas_module');
            $table->index(['agent_id', 'is_active', 'is_deleted'], 'idx_agent_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents_kanvas_modules');
    }
};
