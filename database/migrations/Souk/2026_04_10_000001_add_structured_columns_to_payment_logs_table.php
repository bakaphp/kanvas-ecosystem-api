<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('commerce')->table('payment_logs', function (Blueprint $table) {
            if (! Schema::connection('commerce')->hasColumn('payment_logs', 'event_type')) {
                $table->string('event_type', 50)->nullable()->index()->after('status');
            }

            if (! Schema::connection('commerce')->hasColumn('payment_logs', 'error_code')) {
                $table->string('error_code', 50)->nullable()->index()->after('event_type');
            }

            if (! Schema::connection('commerce')->hasColumn('payment_logs', 'error_message')) {
                $table->string('error_message', 500)->nullable()->after('error_code');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('commerce')->table('payment_logs', function (Blueprint $table) {
            $columns = array_filter(
                ['event_type', 'error_code', 'error_message'],
                fn ($col) => Schema::connection('commerce')->hasColumn('payment_logs', $col)
            );

            if (! empty($columns)) {
                $table->dropColumn(array_values($columns));
            }
        });
    }
};
