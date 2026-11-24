<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('commerce')->table('orders', function (Blueprint $table) {
            if (! Schema::connection('commerce')->hasColumn('orders', 'wallet_tender')) {
                $table->decimal('wallet_tender', 15, 4)->nullable()->after('total_payable_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('commerce')->table('orders', function (Blueprint $table) {
            if (Schema::connection('commerce')->hasColumn('orders', 'wallet_tender')) {
                $table->dropColumn('wallet_tender');
            }
        });
    }
};
