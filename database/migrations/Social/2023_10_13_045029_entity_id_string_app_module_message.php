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
        Schema::connection('social')->table('user_messages_activities', function (Blueprint $table) {

            $table->string('from_entity_id')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('social')->table('user_messages_activities', function (Blueprint $table) {
            $table->integer('from_entity_id')->change();
        });
    }
};
