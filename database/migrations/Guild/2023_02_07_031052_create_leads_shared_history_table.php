<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLeadsSharedHistoryTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('crm')->create('leads_shared_history', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('leads_id')->index('leads_id');
            $table->bigInteger('users_id')->index('users_id');
            $table->char('visitors_id', 36)->index('visitor_id');
            $table->char('receivers_id', 36)->index('reciever_id');
            $table->char('contacts_id', 36)->index('contact_id');
            $table->char('action', 36)->index('action');
            $table->longText('request')->nullable();
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->index('updated_at');
            $table->boolean('is_deleted')->default(false)->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('crm')->dropIfExists('leads_shared_history');
    }
}
