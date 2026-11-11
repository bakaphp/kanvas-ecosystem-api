<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::connection('commerce')->hasColumn('tax_routing_rules', 'rate')) {
            Schema::connection('commerce')->table('tax_routing_rules', function (Blueprint $table) {
                $table->decimal('rate', 8, 6)->default(0)->after('tax_shipping');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('commerce')->hasColumn('tax_routing_rules', 'rate')) {
            Schema::connection('commerce')->table('tax_routing_rules', function (Blueprint $table) {
                $table->dropColumn('rate');
            });
        }
    }
};
