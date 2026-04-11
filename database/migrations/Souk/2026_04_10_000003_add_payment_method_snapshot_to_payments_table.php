<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('commerce')->table('payments', function (Blueprint $table) {
            if (! Schema::connection('commerce')->hasColumn('payments', 'payment_method_snapshot')) {
                $table->json('payment_method_snapshot')->nullable()->after('payment_method');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('commerce')->table('payments', function (Blueprint $table) {
            if (Schema::connection('commerce')->hasColumn('payments', 'payment_method_snapshot')) {
                $table->dropColumn('payment_method_snapshot');
            }
        });
    }
};
