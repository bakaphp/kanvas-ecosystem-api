<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('commerce')->table('customer_tax_exemptions', function (Blueprint $table) {
            if (! Schema::connection('commerce')->hasColumn('customer_tax_exemptions', 'valid_from')) {
                $table->date('valid_from')->nullable()->after('certificate_number');
            }
            if (! Schema::connection('commerce')->hasColumn('customer_tax_exemptions', 'valid_to')) {
                $table->date('valid_to')->nullable()->after('valid_from');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('commerce')->table('customer_tax_exemptions', function (Blueprint $table) {
            $table->dropColumn(['valid_from', 'valid_to']);
        });
    }
};
