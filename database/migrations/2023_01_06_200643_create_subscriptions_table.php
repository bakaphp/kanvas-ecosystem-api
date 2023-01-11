<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSubscriptionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('users_id')->index('user_id');
            $table->integer('user_id');
            $table->integer('companies_id')->index('companies_id');
            $table->integer('companies_branches_id')->index('companies_branches_id');
            $table->integer('companies_groups_id')->index('companies_groups_ids');
            $table->integer('apps_id')->index('apps_id');
            $table->boolean('subscription_types_id')->index('subscription_type_id');
            $table->integer('apps_plans_id');
            $table->string('name', 250);
            $table->string('stripe_id', 250)->index('stripe_id');
            $table->string('stripe_plan', 250)->index('stripe_plan');
            $table->string('stripe_status', 25)->index('stripe_status');
            $table->integer('quantity');
            $table->timestamp('trial_ends_at')->nullable()->index('trial_ends_at');
            $table->dateTime('grace_period_ends')->nullable();
            $table->dateTime('next_due_payment')->nullable();
            $table->timestamp('ends_at')->nullable()->index('ends_at');
            $table->integer('payment_frequency_id')->nullable();
            $table->integer('trial_ends_days')->nullable();
            $table->integer('is_freetrial')->default(0)->index('is_freetrial');
            $table->integer('is_active')->default(0)->index('is_active');
            $table->integer('is_cancelled')->nullable()->default(0)->index('is_cancelled');
            $table->integer('paid')->nullable()->default(0)->index('paid');
            $table->dateTime('charge_date')->nullable()->index('charge_date');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->default(0)->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('subscriptions');
    }
}
