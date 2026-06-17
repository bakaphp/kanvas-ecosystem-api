<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen `payments.authorization_code` from VARCHAR(20) to VARCHAR(64).
 *
 * The original column was sized for short bank auth codes (Azul/CardNet return
 * ~6 chars). The Stripe processor stores the charge id (`ch_…`, ~28 chars) here
 * for cross-processor reconciliation; 20 chars truncates it (silent corruption
 * under MySQL non-strict mode, constraint error under strict). 64 covers Stripe
 * references (`ch_`/`pi_` are typically ~28 chars) with headroom and stays well
 * within the indexable-key limit. Existing short codes are preserved.
 */
return new class () extends Migration {
    protected $connection = 'commerce';

    public function up(): void
    {
        Schema::connection('commerce')->table('payments', function (Blueprint $table) {
            $table->string('authorization_code', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection('commerce')->table('payments', function (Blueprint $table) {
            $table->string('authorization_code', 20)->nullable()->change();
        });
    }
};
