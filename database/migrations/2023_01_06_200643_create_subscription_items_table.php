<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSubscriptionItemsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('subscription_items', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('subscription_id')->index('subscription_id');
            $table->integer('apps_plans_id')->default(0)->index('apps_plans_id');
            $table->string('stripe_id')->index('stripe_id');
            $table->string('stripe_plan')->index('stripe_plan');
            $table->tinyInteger('quantity')->default(1);
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->index('updated_at');
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
        Schema::dropIfExists('subscription_items');
    }
}
