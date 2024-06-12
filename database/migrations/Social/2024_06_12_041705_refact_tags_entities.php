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
        //remove field entity_namespace from tags_entities
        if (Schema::hasColumn('tags_entities', 'entity_namespace')) {
            Schema::table('tags_entities', function (Blueprint $table) {
                $table->dropColumn('entity_namespace');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tags_entities', function (Blueprint $table) {
            $table->string('entity_namespace', 255)->nullable();
        });
    }
};
