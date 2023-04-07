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
        Schema::create('categories', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_520_ci';
            $table->id();
            $table->bigInteger('apps_id')->unsigned();
            $table->bigInteger('companies_id')->unsigned();
            $table->bigInteger('users_id')->unsigned();
            $table->bigInteger('parent_id')->unsigned()->nullable();
            $table->char('uuid', 37)->unique();
            $table->text('name');
            $table->string('slug');
            $table->char('code', 36)->nullable();
            $table->integer('position')->default(0);
            $table->boolean('is_published')->default(0);
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['companies_id', 'slug', 'apps_id']);
            $table->index(['companies_id', 'slug', 'apps_id']);
            $table->index('apps_id');
            $table->index('companies_id');
            $table->index('parent_id');
            $table->index('uuid');
            $table->index('slug');
            $table->index('code');
            $table->index('users_id');
            $table->index('position');
            $table->index('is_deleted');
            $table->index('created_at');
            $table->index('updated_at');
            $table->index('is_published');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('categories');
    }
};
