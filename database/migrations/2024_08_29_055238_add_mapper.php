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
        Schema::table('mappers_importers_templates', function (Blueprint $table) {
            //
            $table->text('mapper')->nullable()->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mappers_importers_templates', function (Blueprint $table) {
            //
            $table->dropColumn('mapper');
        });
    }
};
