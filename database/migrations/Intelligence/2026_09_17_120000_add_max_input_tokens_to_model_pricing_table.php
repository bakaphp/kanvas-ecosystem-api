<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The upstream catalogue is `model_prices_and_context_window.json` — the window was always in the
 * payload and only the prices were kept. ModelContextWindowService needs it to size an agent's
 * history to the model actually answering instead of the smallest context we run.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->table('model_pricing', function (Blueprint $table) {
            $table->unsignedInteger('max_input_tokens')->nullable()->after('output_per_million');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('model_pricing', function (Blueprint $table) {
            $table->dropColumn('max_input_tokens');
        });
    }
};
