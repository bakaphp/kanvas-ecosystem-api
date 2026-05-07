<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits agents.user_id into two distinct concerns:
 *   - user_id (persona)        — the user the agent acts as at runtime: posts messages,
 *                                 owns Sessions, drives loop guards. Existing column.
 *   - created_by_users_id (audit) — the human who configured the agent. New column,
 *                                   backfilled from user_id so existing rows stay correct.
 *
 * UI follow-up: let users change the persona without touching audit.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table('agents', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by_users_id')->nullable()->after('user_id')->index();
        });

        DB::connection('intelligence')->table('agents')
            ->whereNull('created_by_users_id')
            ->update(['created_by_users_id' => DB::raw('user_id')]);
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('agents', function (Blueprint $table) {
            $table->dropIndex(['created_by_users_id']);
            $table->dropColumn('created_by_users_id');
        });
    }
};
