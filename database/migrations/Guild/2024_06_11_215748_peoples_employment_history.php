<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('peoples_employment_history', function (Blueprint $table) {
            $table->id();
            $table->integer('peoples_id')->unsigned();
            $table->integer('apps_id')->unsigned();
            $table->string('position');
            $table->decimal('income', 10, 2)->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->integer('status')->default(0);
            $table->string('income_type')->nullable();
            $table->string('company_employer_name');
            $table->string('company_employer_address')->nullable();
            $table->string('company_employer_phone')->nullable();
            $table->string('company_employer_email')->nullable();
            $table->string('company_employer_city')->nullable();
            $table->string('company_employer_state')->nullable();
            $table->string('company_employer_zip')->nullable();
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
