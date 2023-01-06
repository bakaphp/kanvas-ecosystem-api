<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserLinkedSourcesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_linked_sources', function (Blueprint $table) {
            $table->unsignedInteger('users_id')->index('user_id');
            $table->unsignedInteger('source_id');
            $table->string('source_users_id')->index('source_user_id');
            $table->string('source_users_id_text')->nullable();
            $table->string('source_username', 45)->index('source_username');
            $table->dateTime('created_at')->nullable()->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->nullable()->default(false)->index('is_deleted');

            $table->primary(['users_id', 'source_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('user_linked_sources');
    }
}
