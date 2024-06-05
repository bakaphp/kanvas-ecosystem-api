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
        Schema::create('request_deleted_accounts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('apps_id')->unsigned();
            $table->bigInteger('users_id')->unsigned();
            $table->string('email');
            $table->string('reason')->nullable();
            $table->dateTime('request_date');
            $table->integer('is_deleted')->default(0);
            $table->timestamps();
            $table->index(['apps_id', 'users_id', 'request_date']);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('request_deleted_accounts');
    }
};
