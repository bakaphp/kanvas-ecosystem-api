<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::create('model_pricing', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50);
            $table->string('model', 150);
            $table->decimal('input_per_million', 10, 4)->default(0);
            $table->decimal('output_per_million', 10, 4)->default(0);
            $table->decimal('cache_read_per_million', 10, 4)->nullable();
            $table->decimal('cache_write_per_million', 10, 4)->nullable();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->text('notes')->nullable();
            $table->string('source', 50)->default('manual'); // 'litellm' | 'openrouter' | 'manual'
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->index(['provider', 'model', 'effective_from'], 'idx_model_pricing_lookup');
            $table->index('effective_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_pricing');
    }
};
