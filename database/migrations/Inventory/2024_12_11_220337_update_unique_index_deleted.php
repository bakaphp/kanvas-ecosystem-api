<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('categories', function (Blueprint $table) {
            // Add the new unique index
            $table->unique(['companies_id', 'slug', 'apps_id', 'is_deleted'], 'categories_companies_id_slug_apps_id_is_deleted_unique');
            // Optionally drop the old unique index
            $table->dropUnique(['companies_id', 'slug', 'apps_id']); // Use name if explicit name was defined earlier
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->unique(['companies_id', 'slug', 'apps_id', 'is_deleted'], 'channels_companies_id_slug_apps_id_is_deleted_unique');
            $table->dropUnique(['slug', 'companies_id', 'apps_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
};
