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
        Schema::table('filesystem_entities', function (Blueprint $table) {
            // Make sure no foreign keys are attached to the column before altering
            $table->unsignedBigInteger('entity_id')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('filesystem_entities', function (Blueprint $table) {
            // Reverse back to CHAR(36) if needed
            $table->char('entity_id', 36)->change();
        });
    }
};
