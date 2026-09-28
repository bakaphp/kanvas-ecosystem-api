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
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('event')->table('participant_passes', function (Blueprint $table) {
            $table->date('issue_date')->nullable()->after('expiration_date');
        });
    }

    public function down(): void
    {
        Schema::connection('event')->table('participant_passes', function (Blueprint $table) {
            $table->dropColumn('issue_date');
        });
    }
};
