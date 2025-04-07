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
        Schema::table('event_versions', function (Blueprint $table) {
            $table->integer('total_attendees')->after('price_per_ticket')->default(0)->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_versions', function (Blueprint $table) {
            $table->dropColumn('total_attendees');
        });
    }
};
