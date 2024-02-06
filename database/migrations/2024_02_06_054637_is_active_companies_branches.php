<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('ecosystem')->table('companies_branches', function (Blueprint $table) {
            $table->boolean('is_active')->after('phone')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies_branches', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
