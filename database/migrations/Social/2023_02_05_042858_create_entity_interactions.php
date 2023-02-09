<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('entity_interactions', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->char('entity_id', 36)->index('entity_id');
            $table->char('entity_namespace')->index('entity_namespace');
            $table->integer('interactions_id')->index('interactions_id');
            $table->char('interacted_entity_id', 36)->index('interacted_entity_id');
            $table->char('interacted_entity_namespace')->index('interacted_entity_namespace');
            $table->longText('notes')->nullable();
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->default(false)->index('is_deleted');

            $table->index(['entity_id', 'entity_namespace', 'interacted_entity_id', 'interacted_entity_namespace', 'interactions_id', 'is_deleted'], 'entity_interactionss_id_is_deleted');
            $table->index(['entity_id', 'entity_namespace', 'interactions_id', 'is_deleted'], 'entity_interactions_id_2');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('entity_interactions');
    }
};
