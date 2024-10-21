<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('event_version_dates', function (Blueprint $table) {
            //
            $table->time('start_time')->nullable()->change();
            $table->time('end_time')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_version_dates', function (Blueprint $table) {
            //
        });
    }
};
