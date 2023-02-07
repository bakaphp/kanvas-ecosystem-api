<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAgentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('crm')->create('agents', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->string('name', 150);
            $table->bigInteger('users_id')->nullable()->index('users_id');
            $table->char('users_linked_source_id', 150)->nullable()->index('user_linked_source_id');
            $table->bigInteger('companies_id')->index('companies_id');
            $table->char('member_id', 50)->nullable()->index('member_id');
            $table->bigInteger('owner_id')->index('owner_id');
            $table->char('owner_linked_source_id', 150)->nullable()->index('owner_linked_source_id');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->tinyInteger('is_deleted')->default(0)->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('crm')->dropIfExists('agents');
    }
}
