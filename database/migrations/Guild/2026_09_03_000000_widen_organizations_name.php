<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen `organizations.name` from VARCHAR(128) to VARCHAR(255).
 *
 * A real Salesforce Account backfill (`kanvas:salesforce-backfill`) hit
 * `SQLSTATE[22001]: String data, right truncated` on an estate/trust-style
 * Account whose Name lists multiple beneficiaries (196 chars) — e.g.
 * "R.E. Nohlgren, Hannah V. Nohlgren, Estate of Harold Boyd Harold E. Hansen,
 * Lori Hanson Olson, ...". 128 chars is too tight for that naming pattern;
 * 255 matches the column's own `shortname`-adjacent siblings elsewhere in
 * this schema and gives real headroom without going unbounded.
 */
return new class () extends Migration {
    protected $connection = 'crm';

    public function up(): void
    {
        Schema::connection('crm')->table('organizations', function (Blueprint $table) {
            $table->string('name', 255)->change();
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->table('organizations', function (Blueprint $table) {
            // Will fail with data-truncation if any row's name is already >128
            // chars — that's the right behavior, silently truncating real
            // organization names on rollback would be worse than failing loud.
            $table->string('name', 128)->change();
        });
    }
};
