<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('companies_kanvas_modules', function (Blueprint $table) {
            // Distinct from `is_active` — `is_active` is the entitlement
            // (does this company have the right to use the module);
            // `status` is the configuration health (is it actually wired
            // and working).
            $table->string('status', 32)->default('not_connected')->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('companies_kanvas_modules', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
