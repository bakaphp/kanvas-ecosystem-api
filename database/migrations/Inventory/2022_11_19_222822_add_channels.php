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
        Schema::create('channels', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_520_ci';
            $table->id();
            $table->bigInteger('users_id');
            $table->bigInteger('companies_id');
            $table->bigInteger('apps_id');
            $table->char('uuid', 37)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('slug');
            $table->integer('is_published')->default(0);
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index(['slug', 'companies_id', 'apps_id']);
            $table->unique(['slug', 'companies_id', 'apps_id']);
            $table->index('uuid');
            $table->index('slug');
            $table->index('users_id');
            $table->index('companies_id');
            $table->index('apps_id');
            $table->index('is_published');
            $table->index('is_deleted');
            $table->index('created_at');
            $table->index('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('channels');
    }
};
