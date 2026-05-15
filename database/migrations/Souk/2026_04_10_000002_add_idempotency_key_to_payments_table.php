<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('commerce')->table('payments', function (Blueprint $table) {
            if (! Schema::connection('commerce')->hasColumn('payments', 'idempotency_key')) {
                $table->string('idempotency_key', 64)->nullable()->unique()->after('uuid');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('commerce')->table('payments', function (Blueprint $table) {
            if (Schema::connection('commerce')->hasColumn('payments', 'idempotency_key')) {
                $table->dropColumn('idempotency_key');
            }
        });
    }
};
