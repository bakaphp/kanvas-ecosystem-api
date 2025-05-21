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
        Schema::create('payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('apps_id')->index();
            $table->bigInteger('companies_id')->index();
            $table->bigInteger('payable_id')->index();
            $table->string('payable_type', 255)->index();
            $table->bigInteger('users_id')->index();
            $table->bigInteger('payment_methods_id')->index();
            $table->date('payment_date');
            $table->date('document_date');
            $table->string('payment_method', 255)->nullable();
            $table->string('concept', 255)->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('currency', 10)->nullable()->index();
            $table->longText('metadata')->nullable();
            $table->timestamps(); // Includes both `created_at` and `updated_at` columns
            $table->boolean('is_deleted')->default(false)->index();
        });

    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('payments');
    }
};
