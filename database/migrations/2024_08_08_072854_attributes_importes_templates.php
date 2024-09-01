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
        Schema::create('attributes_mappers_importers_templates', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('importers_templates_id')->unsigned()->index();
            $table->bigInteger('parent_id')->unsigned()->index(); // This is the parent attribute id from the attributes_importers_templates table
            $table->string('name');
            $table->string('mapping_field');
            $table->integer('is_deleted')->default(0);
            $table->timestamps();

            //$table->index(['importers_templates_id', 'parent_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attributes_importers_templates');
    }
};
