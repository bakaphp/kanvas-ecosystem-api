<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->unsignedBigInteger('workflows_id')->after('handler')->nullable(true);
            $table->unsignedBigInteger('receivers_id')->after('workflows_id')->nullable(true);

            $table->foreign('workflows_id')->references('id')->on('workflows');
            $table->foreign('receivers_id')->references('id')->on('receiver_webhooks');

            $table->index('workflows_id', 'workflows_id_index');
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
