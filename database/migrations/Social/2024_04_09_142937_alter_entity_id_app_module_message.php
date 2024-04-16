<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('social')->table('app_module_message', function ($table) {
            $table->string('entity_id', 50)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('social')->table('app_module_message', function ($table) {
            $table->integer('entity_id')->nullable()->change();
        });
    }
};
