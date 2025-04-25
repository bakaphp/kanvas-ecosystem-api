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
        Schema::table('entity_integration_history', function (Blueprint $table) {
            $table->unsignedBigInteger('rules_id')->after('workflow_id')->nullable(true);

            $table->index('rules_id', 'rules_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {

    }
};
