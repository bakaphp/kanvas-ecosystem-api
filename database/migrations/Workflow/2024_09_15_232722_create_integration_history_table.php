<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('entity_integration_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('apps_id')->default(0);
            $table->string('entity_namespace');
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('integrations_company_id');
            $table->unsignedBigInteger('status_id');
            $table->text('response')->nullable();
            $table->text('exception')->nullable();
            $table->timestamps();
            $table->tinyInteger('is_deleted')->default(0);

            // Foreign key constraints
            $table->foreign('integrations_company_id')->references('id')->on('integration_companies');
            $table->foreign('status_id')->references('id')->on('status');

            $table->index('entity_id', 'entity_id_index');
            $table->index('status_id', 'status_id_index');
            $table->index('integrations_company_id', 'integrations_company_id_index');
            $table->index('apps_id', 'apps_id_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('entity_integration_history');
    }
};
