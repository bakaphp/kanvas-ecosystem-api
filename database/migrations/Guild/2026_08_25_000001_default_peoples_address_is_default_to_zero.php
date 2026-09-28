<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `is_default` marks the person's CURRENT address, so defaulting it to 1 meant every insert that
 * never wrote the column claimed to be it — that is how previous-home rows from a credit app ended
 * up shadowing the address the customer actually lives at. Promotion is opt-in now, through
 * `People::addDefaultAddress()`, which is also the only thing that demotes the prior row.
 *
 * `ALTER COLUMN ... SET DEFAULT` touches metadata only — no table copy, no row rewrite. Existing
 * rows keep their value; `kanvas:guild:repair-people-addresses` cleans those up.
 */
return new class () extends Migration {
    public function up(): void
    {
        DB::connection('crm')->statement('ALTER TABLE peoples_address ALTER COLUMN is_default SET DEFAULT 0');
    }

    public function down(): void
    {
        DB::connection('crm')->statement('ALTER TABLE peoples_address ALTER COLUMN is_default SET DEFAULT 1');
    }
};
