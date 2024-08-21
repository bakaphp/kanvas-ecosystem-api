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
        Schema::table('attributes_mappers_importers_templates', function (Blueprint $table) {
            //
            $table->bigInteger('parent_id')->unsigned()->nullable()->change();
        });

        Schema::table('attributes_mappers_importers_templates', function (Blueprint $table) {
            //
            $table->string('path')->nullable()->index()->after('parent_id');
        });
        Schema::table('attributes_mappers_importers_templates', function (Blueprint $table) {
            $table->foreign('parent_id')
                ->references('id')
                ->on('attributes_mappers_importers_templates')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attributes_mappers_importers_templates', function (Blueprint $table) {
            //
        });
    }
};
