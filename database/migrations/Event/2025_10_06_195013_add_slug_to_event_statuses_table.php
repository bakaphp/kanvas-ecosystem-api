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
        Schema::table('event_statuses', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_statuses', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
