<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateTokenSessionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->integer('apps_id')->default(0)->index('apps_id')->after('users_id');
            $table->text('refresh_token')->nullable()->after('is_admin');
            $table->text('abilities')->nullable()->after('is_admin');
            $table->timestamp('expires_at')->nullable()->index('expires_at')->after('is_admin');
            $table->timestamp('refresh_token_expires_at')->nullable()->index('refresh_token_expires_at')->after('is_admin');
        });

        Schema::table('session_keys', function (Blueprint $table) {
            $table->string('name', 150)->nullable()->after('users_id');
        });
    }
}
