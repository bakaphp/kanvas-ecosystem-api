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
        Schema::create('order_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_types_id')->constrained();
            $table->bigInteger('apps_id')->index();
            $table->bigInteger('companies_id')->nullable()->index();
            $table->string('slug');
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_deleted')->default(false);
            $table->boolean('is_final')->default(false);
            $table->timestamps();
            $table->unique(['order_types_id', 'apps_id', 'slug']);
        });

        Schema::create('order_status_transitions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('apps_id')->index();
            $table->bigInteger('companies_id')->nullable()->index();
            $table->foreignId('order_types_id')->constrained('order_types');
            $table->foreignId('from_status_id')->constrained('order_statuses');
            $table->foreignId('to_status_id')->constrained('order_statuses');
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('metadata')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();
            $table->unique(['order_types_id', 'apps_id', 'from_status_id', 'to_status_id'], 'order_status_transitions_unique');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('order_status_id')->nullable()->constrained();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('order_status_id');
        });

        Schema::dropIfExists('order_status');

        Schema::dropIfExists('order_status_transitions');
    }
};
