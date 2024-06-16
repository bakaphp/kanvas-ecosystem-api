<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('peoples_employment_history', function (Blueprint $table) {
            $table->id();
            $table->integer('peoples_id')->unsigned()->index('peoples_id');
            $table->integer('apps_id')->unsigned()->index('apps_id');
            $table->string('position');
            $table->decimal('income', 10, 2)->nullable();
            $table->date('start_date')->index();
            $table->date('end_date')->nullable()->index();
            $table->integer('status')->default(0)->index();
            $table->string('income_type')->nullable();
            $table->string('company_name');
            $table->string('company_address')->nullable();
            $table->string('company_phone')->nullable();
            $table->string('company_email')->nullable();
            $table->string('company_city')->nullable();
            $table->string('company_state')->nullable();
            $table->string('company_zip')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('peoples_employment_history');
    }
};
