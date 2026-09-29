<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courtesy passes carry an issue date in SIPGO and the Gestor filters on it.
 *
 * `participant_passes` already has `expiration_date`, `used_date` and `revoked_at` but nothing
 * for when the pass was issued, so both legacy tables — `courtsey_passes` and
 * `companies_courtsey_passes` — had nowhere to put `issue_date`. Nullable because passes created
 * inside Kanvas do not set one.
 *
 * Lives under `Event/` because `KanvasSetupCommand` runs the root `migrate` first and
 * `--path database/migrations/Event/ --database event` eight steps later: a root migration
 * naming the `event` connection runs before that database has any tables, whatever its
 * timestamp says. The connection comes from `--database`, so `Schema::table()` is enough here.
 */
return new class () extends Migration {
    /**
     * Guarded because this file moved. It first shipped in the root migrations directory, where
     * it ran against the default connection's ledger — so an environment that already applied it
     * has the column but no row for it in `event.migrations`, and re-running unguarded would
     * fail on a duplicate column. A fresh database takes the `if` and a migrated one skips it,
     * and both end up recorded against the `event` connection from here on.
     */
    public function up(): void
    {
        if (Schema::hasColumn('participant_passes', 'issue_date')) {
            return;
        }

        Schema::table('participant_passes', function (Blueprint $table) {
            $table->date('issue_date')->nullable()->after('expiration_date');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('participant_passes', 'issue_date')) {
            return;
        }

        Schema::table('participant_passes', function (Blueprint $table) {
            $table->dropColumn('issue_date');
        });
    }
};
