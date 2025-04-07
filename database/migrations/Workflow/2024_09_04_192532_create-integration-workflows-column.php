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
        Schema::table('integrations', function (Blueprint $table) {
            $table->unsignedBigInteger('actions_id')->after('handler')->nullable(true);
            $table->unsignedBigInteger('receivers_id')->after('actions_id')->nullable(true);

            $table->foreign('actions_id')->references('id')->on('actions');
            $table->foreign('receivers_id')->references('id')->on('receiver_webhooks');

            $table->index('actions_id', 'actions_id_index');
            $table->index('receivers_id', 'receivers_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
