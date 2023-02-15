<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMessagesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('social')->create('messages', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('parent_id')->nullable()->default(0)->index('parent_id');
            $table->char('parent_unique_id', 64)->nullable()->index('parent_unique_id');
            $table->char('uuid', 36)->nullable()->index('uuidindex');
            $table->integer('apps_id')->index('apps_id');
            $table->bigInteger('companies_id')->index('companies_id');
            $table->bigInteger('users_id')->index('users_id');
            $table->bigInteger('message_types_id')->index('message_types_id');
            $table->longText('message');
            $table->integer('reactions_count')->nullable()->default(0)->index('reactions_count');
            $table->integer('comments_count')->nullable()->default(0)->index('comments_count');
            $table->integer('total_liked')->nullable()->default(0)->index('total_likes');
            $table->integer('total_saved')->nullable()->default(0)->index('total_saved');
            $table->integer('total_shared')->nullable()->default(0)->index('total_shared');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->default(false)->index('is_deleted');

            $table->index(['apps_id', 'companies_id'], 'apps_id_companies_id');
            $table->index(['id', 'apps_id', 'is_deleted'], 'id');
            $table->index(['apps_id', 'users_id', 'message_types_id', 'is_deleted'], 'apps_id_users_id_message_types_id_is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('social')->dropIfExists('messages');
    }
}
