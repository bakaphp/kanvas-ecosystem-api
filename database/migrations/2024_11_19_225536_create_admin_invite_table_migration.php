<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('admin_invite', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('invite_hash', 200)->index('invite_hash');
            $table->integer('users_id')->index('users_id');
            $table->integer('apps_id')->index('app_id');
            $table->string('email')->index('email');
            $table->string('firstname', 64)->nullable();
            $table->string('lastname', 64)->nullable();
            $table->text('description')->nullable();
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->default(0)->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_invite_table_migration');
    }
};
