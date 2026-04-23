<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'commerce';

    public function up(): void
    {
        Schema::connection('commerce')->table('payments', function (Blueprint $table) {
            if (! Schema::connection('commerce')->hasColumn('payments', 'payment_method_brand')) {
                $table->string('payment_method_brand', 32)->nullable()->after('payment_method');
            }

            if (! Schema::connection('commerce')->hasColumn('payments', 'payment_method_last_four')) {
                $table->string('payment_method_last_four', 4)->nullable()->after('payment_method_brand');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('commerce')->table('payments', function (Blueprint $table) {
            $columns = array_filter(
                ['payment_method_brand', 'payment_method_last_four'],
                fn ($col) => Schema::connection('commerce')->hasColumn('payments', $col)
            );

            if (! empty($columns)) {
                $table->dropColumn(array_values($columns));
            }
        });
    }
};
