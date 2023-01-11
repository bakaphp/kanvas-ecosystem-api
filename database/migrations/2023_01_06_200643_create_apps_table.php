<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAppsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('apps', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 45)->nullable()->index('name');
            $table->string('description', 45)->nullable();
            $table->string('url')->nullable();
            $table->string('domain', 100)->nullable()->index('domain');
            $table->integer('default_apps_plan_id')->nullable();
            $table->boolean('is_actived')->nullable()->default(false)->index('is_actived');
            $table->string('key', 128)->nullable()->index('key');
            $table->integer('payments_active')->nullable()->index('payments_active');
            $table->boolean('subscription_types_id')->nullable()->index('subscription_type');
            $table->tinyInteger('ecosystem_auth')->default(0)->index('ecosystem_auth');
            $table->integer('is_public')->nullable()->index('is_public');
            $table->boolean('domain_based')->nullable()->default(false)->index('domain_based');
            $table->dateTime('created_at')->nullable()->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->nullable()->index('is_deleted');

            $table->index(['domain', 'domain_based'], 'domain_domain_based');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('apps');
    }
}
