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
            $table->integer('apps_id')->default(0)->index('apps_id');
            $table->text('refresh_token')->nullable();
            $table->text('abilities')->nullable();
            $table->timestamp('expires_at')->nullable()->index('expires_at');
            $table->timestamp('refresh_token_expires_at')->nullable()->index('refresh_token_expires_at');
        });
    }
}
