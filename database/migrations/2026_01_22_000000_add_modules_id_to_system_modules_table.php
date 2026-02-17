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
            $table->unsignedBigInteger('modules_id')->nullable()->after('parents_id')->index('modules_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_modules', function (Blueprint $table) {
            $table->dropIndex('modules_id');
            $table->dropColumn('modules_id');
        });
    }
};
