<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('commerce')->table('tax_routing_rules', function (Blueprint $table) {
            if (! Schema::connection('commerce')->hasColumn('tax_routing_rules', 'name')) {
                $table->string('name', 128)->nullable()->after('apps_id');
            }
            if (! Schema::connection('commerce')->hasColumn('tax_routing_rules', 'condition_country')) {
                $table->char('condition_country', 10)->nullable()->after('name');
            }
            if (! Schema::connection('commerce')->hasColumn('tax_routing_rules', 'condition_state')) {
                $table->string('condition_state', 10)->nullable()->after('condition_country');
            }
            // destination_country/state were NOT NULL in the original migration; make nullable
            // now that routing rules are matched by condition_country/state instead
            $table->string('destination_country', 10)->nullable()->default(null)->change();
            $table->string('destination_state', 10)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::connection('commerce')->table('tax_routing_rules', function (Blueprint $table) {
            $table->dropColumn(['name', 'condition_country', 'condition_state']);
        });
    }
};
