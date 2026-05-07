<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('inventory')->create('categories_entities', function (Blueprint $table) {
            $table->id();
            $table->integer('categories_id')->index();
            $table->integer('entity_id')->index();
            $table->string('taggable_type')->index();
            $table->integer('companies_id')->index();
            $table->integer('apps_id')->index();
            $table->integer('users_id')->index();
            $table->boolean('is_deleted')->default(0)->index();
            $table->timestamps();

            $table->index(['apps_id', 'entity_id', 'taggable_type', 'categories_id'], 'entity_category_index2');
            $table->index(['entity_id', 'taggable_type', 'categories_id'], 'entity_category_index');
            $table->index(['apps_id', 'taggable_type', 'categories_id'], 'type_category_index2');
            $table->index(['taggable_type', 'categories_id'], 'type_category_index');
            $table->index(['apps_id', 'entity_id', 'taggable_type', 'categories_id', 'is_deleted'], 'entity_category_deleted_index');
            $table->index(['entity_id', 'taggable_type', 'categories_id', 'is_deleted'], 'entity_category_index3');
            $table->index(['apps_id', 'taggable_type', 'categories_id', 'is_deleted'], 'type_category_deleted_index');
            $table->index(['taggable_type', 'categories_id', 'is_deleted'], 'type_category_index3');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('inventory')->dropIfExists('categories_entities');
    }
};
