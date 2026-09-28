<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('commerce')->table('payment_logs', function (Blueprint $table) {
            if (! Schema::connection('commerce')->hasColumn('payment_logs', 'processor_response_code')) {
                $table->string('processor_response_code', 10)->nullable()->index()->after('error_message');
            }

            if (! Schema::connection('commerce')->hasColumn('payment_logs', 'response_insight')) {
                $table->string('response_insight', 50)->nullable()->index()->after('processor_response_code');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('commerce')->table('payment_logs', function (Blueprint $table) {
            $columns = array_filter(
                ['processor_response_code', 'response_insight'],
                fn ($col) => Schema::connection('commerce')->hasColumn('payment_logs', $col)
            );

            if (! empty($columns)) {
                $table->dropColumn(array_values($columns));
            }
        });
    }
};
