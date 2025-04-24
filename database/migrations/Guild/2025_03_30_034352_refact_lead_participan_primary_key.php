<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leads_participants', function (Blueprint $table) {
            // First, drop the existing composite primary key
            $table->dropPrimary(['leads_id', 'peoples_id']);

            // Add a new auto-increment id as primary key
            $table->id()->first();

            // Keep the existing columns unique together
            $table->unique(['leads_id', 'peoples_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads_participants', function (Blueprint $table) {
            // Drop the unique constraint
            $table->dropUnique(['leads_id', 'peoples_id']);

            // Drop the id column
            $table->dropColumn('id');

            // Restore the original composite primary key
            $table->primary(['leads_id', 'peoples_id']);
        });
    }
};
