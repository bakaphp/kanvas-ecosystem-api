<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLeadsLinkedSourcesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('crm')->create('leads_linked_sources', function (Blueprint $table) {
            $table->smallInteger('source_id');
            $table->unsignedBigInteger('leads_id');
            $table->bigInteger('source_leads_id')->index('source_leads_id');
            $table->string('source_leads_id_text')->nullable()->index('source_leads_id_text');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->default(false)->index('is_deleted');

            $table->primary(['source_id', 'leads_id', 'source_leads_id']);
            $table->index(['source_id', 'leads_id'], 'source_id_leads_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('crm')->dropIfExists('leads_linked_sources');
    }
}
