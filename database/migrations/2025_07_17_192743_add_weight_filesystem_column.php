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
        Schema::table('filesystem_entities', function (Blueprint $table) {
            $table->decimal('weight', 5)->default(0)->after('field_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('filesystem_entities', function (Blueprint $table) {
            $table->dropColumn('weight');
        });
    }
};
