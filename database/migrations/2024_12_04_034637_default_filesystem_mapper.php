<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('filesystem_mappers', function (Blueprint $table) {
            //
            $table->boolean('is_default')->default(0)->after('configuration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('filesystem_mappers', function (Blueprint $table) {
            //
            $table->dropColumn('is_default');
        });
    }
};
