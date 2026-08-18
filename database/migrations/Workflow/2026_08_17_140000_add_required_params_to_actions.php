<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `params` describes a step's knobs in prose, which is enough for an agent to read but not enough for
 * anything to check. This column names the ones a step cannot run correctly without, so rule creation
 * can refuse instead of producing a rule that fires and does the wrong thing — the WordPress publisher
 * with no `message_type_id` does not error, it publishes every message on the channel.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        Schema::connection('workflow')->table('actions', function (Blueprint $table): void {
            $table->json('required_params')->nullable()->after('params');
        });
    }

    public function down(): void
    {
        Schema::connection('workflow')->table('actions', function (Blueprint $table): void {
            $table->dropColumn('required_params');
        });
    }
};
