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
            $table->integer('organizations_id')->unsigned()->index('organizations_id');
            $table->integer('peoples_id')->unsigned()->index('peoples_id');
            $table->integer('apps_id')->unsigned()->index('apps_id');
            $table->string('position');
            $table->decimal('income', 10, 2)->nullable();
            $table->date('start_date')->index();
            $table->date('end_date')->nullable()->index();
            $table->integer('status')->default(0)->index();
            $table->string('income_type')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
        });

        //add to organizations email, city, state, zip
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('email')->after('name')->index()->nullable();
            $table->string('city')->after('email')->nullable();
            $table->string('state')->after('city')->nullable();
            $table->string('zip')->after('state')->nullable();
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
