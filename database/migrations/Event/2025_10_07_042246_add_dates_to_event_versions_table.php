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
        Schema::table('event_versions', function (Blueprint $table) {
            $table->dateTime('start_at')->nullable()->after('metadata')->index();
            $table->dateTime('end_at')->nullable()->after('start_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_versions', function (Blueprint $table) {
            $table->dropColumn(['start_at', 'end_at']);
        });
    }
};
