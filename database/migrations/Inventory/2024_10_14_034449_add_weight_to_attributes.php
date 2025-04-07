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
        Schema::table('attributes', function (Blueprint $table) {
            $table->decimal('weight', 5)->default(0)->after('is_visible')->index();
            $table->index('is_filtrable');
            $table->index('is_searchable');
            $table->index('is_visible');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attributes', function (Blueprint $table) {
            $table->dropColumn('weight');
            $table->dropIndex('is_filterable');
            $table->dropIndex('is_searchable');
            $table->dropIndex('is_visible');
        });
    }
};
