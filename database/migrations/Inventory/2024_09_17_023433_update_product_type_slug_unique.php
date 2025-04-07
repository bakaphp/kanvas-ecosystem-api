<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('products_types', function (Blueprint $table) {
            // Drop the existing unique constraint on companies_id and slug
            $table->dropUnique(['companies_id', 'slug']);

            // Add the new unique constraint with apps_id included
            $table->unique(['apps_id', 'companies_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('products_types', function (Blueprint $table) {
            // Drop the unique constraint with apps_id
            $table->dropUnique(['apps_id', 'companies_id', 'slug']);

            // Reinstate the original unique constraint on companies_id and slug
            $table->unique(['companies_id', 'slug']);
        });
    }
};
