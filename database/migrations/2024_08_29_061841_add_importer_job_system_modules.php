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
        Schema::table('system_modules', function (Blueprint $table) {
            //
            $table->string('importer_job')->nullable()->after('bulk_actions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_modules', function (Blueprint $table) {
            //
        });
    }
};
