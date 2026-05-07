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
            $table->tinyInteger('use_import')->after('fields')->default(0)->index('use_import');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};
