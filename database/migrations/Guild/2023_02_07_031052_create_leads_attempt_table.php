<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLeadsAttemptTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('crm')->create('leads_attempt', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->integer('companies_id')->index('company_id');
            $table->integer('leads_id')->nullable()->index('leads_id');
            $table->longText('request')->nullable();
            $table->longText('header')->nullable();
            $table->char('ip', 50)->nullable()->index('ip');
            $table->string('source', 45)->nullable();
            $table->string('public_key', 45)->nullable()->index('public_key');
            $table->integer('processed')->nullable()->index('processed');
            $table->dateTime('created_at')->nullable()->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->default(false)->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('crm')->dropIfExists('leads_attempt');
    }
}
