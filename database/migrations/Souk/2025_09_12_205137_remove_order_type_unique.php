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
        Schema::table('order_types', function (Blueprint $table) {
            $table->dropUnique(['apps_id', 'name']);
            $table->unique(['name', 'companies_id', 'apps_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {

    }
};
