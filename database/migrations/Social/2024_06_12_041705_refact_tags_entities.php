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
            $table->dropColumn('entity_namespace');
            $table->dropColumn('apps_id');
            $table->dropColumn('companies_id');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tags_entities', function (Blueprint $table) {
            $table->string('entity_namespace', 255)->nullable();
            $table->integer('apps_id')->nullable();
            $table->integer('companies_id')->nullable();
        });
    }
};
