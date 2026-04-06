<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'commerce';

    public function up(): void
    {
        Schema::connection('commerce')->table('payments', function (Blueprint $table) {
            if (! Schema::connection('commerce')->hasColumn('payments', 'processor')) {
                $table->string('processor', 50)->nullable()->index()->after('payment_method');
            }

            if (! Schema::connection('commerce')->hasColumn('payments', 'authorization_code')) {
                $table->string('authorization_code', 20)->nullable()->index()->after('processor');
            }

            if (! Schema::connection('commerce')->hasColumn('payments', 'number')) {
                $table->string('number', 75)->nullable()->index()->after('authorization_code');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('commerce')->table('payments', function (Blueprint $table) {
            $columns = array_filter(
                ['processor', 'authorization_code', 'number'],
                fn ($col) => Schema::connection('commerce')->hasColumn('payments', $col)
            );

            if (! empty($columns)) {
                $table->dropColumn(array_values($columns));
            }
        });
    }
};
