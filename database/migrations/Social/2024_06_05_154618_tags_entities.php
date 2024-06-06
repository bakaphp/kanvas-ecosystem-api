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
            $table->integer('tags_id')->index('tags_id');
            $table->integer('entity_id')->index('entity_id');
            $table->string('entity_namespace')->index('entity_namespace');
            $table->integer('companies_id')->index('companies_id');
            $table->integer('apps_id')->index('apps_id');
            $table->integer('users_id');
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->index('created_at')->useCurrent();
            $table->datetime('updated_at')->nullable()->index('updated_at');
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
