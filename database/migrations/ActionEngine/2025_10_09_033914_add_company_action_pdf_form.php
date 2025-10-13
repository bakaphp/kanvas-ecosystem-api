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
        Schema::table('companies_actions', function (Blueprint $table) {
            $table->longText('pdf_config')->nullable()->after('form_config');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies_actions', function (Blueprint $table) {
            $table->dropColumn('pdf_config');
        });
    }
};
