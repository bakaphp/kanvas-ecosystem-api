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
        Schema::create('tags_entities', function (Blueprint $table) {
            $table->id();
            $table->integer('tags_id')->index();
            $table->integer('entity_id')->index();
            $table->string('entity_namespace')->index(); //@todo remove
            $table->integer('companies_id')->index();
            $table->integer('apps_id')->index();
            $table->integer('users_id')->index();
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->index()->useCurrent();
            $table->datetime('updated_at')->nullable()->index();
            $table->index(['tags_id', 'entity_id', 'is_deleted'], 'tags_entities_index_tag');
            $table->index(['tags_id', 'entity_id', 'apps_id', 'is_deleted'], 'tags_entities_app_index');
            $table->index(['tags_id', 'entity_id', 'companies_id', 'apps_id', 'is_deleted'], 'tags_entities_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tags_entities');
    }
};
