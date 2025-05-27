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
        Schema::create('order_types', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('apps_id')->index();
            $table->bigInteger('companies_id')->nullable()->index();
            $table->string('name', 255)->index();
            $table->timestamps();
            $table->boolean('is_deleted')->default(false)->index();

            $table->unique(['apps_id', 'name']);
        });

        Schema::create('payment_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('apps_id')->index();
            $table->bigInteger('companies_id')->index();
            $table->bigInteger('users_id')->index();
            $table->bigInteger('payments_id')->index();
            $table->string('status', 255)->index();
            $table->longText('metadata')->nullable();
            $table->timestamps();
            $table->boolean('is_deleted')->default(false)->index();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->bigInteger('order_types_id')->after('id')->index()->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('order_types');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('order_types_id');
        });
    }
};
