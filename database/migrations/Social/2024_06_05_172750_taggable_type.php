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
        Schema::table('tags_entities', function (Blueprint $table) {
            $table->string('taggable_type')->nullable()->after('entity_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tags_entities', function (Blueprint $table) {
            $table->dropColumn('taggable_type');
        });
    }
};
