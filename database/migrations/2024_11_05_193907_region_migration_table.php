<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_520_ci';
            $table->id();
            $table->bigInteger('apps_id')->unsigned();
            $table->bigInteger('companies_id')->unsigned();
            $table->bigInteger('users_id')->unsigned();
            $table->bigInteger('currency_id')->unsigned();
            $table->char('uuid', 37)->unique();
            $table->string('name', 64);
            $table->string('slug', 32);
            $table->string('short_slug', 32);
            $table->text('settings')->nullable();
            $table->boolean('is_default');
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['companies_id', 'slug', 'apps_id']);
            $table->index(['companies_id', 'slug', 'apps_id']);
            $table->index('apps_id');
            $table->index('companies_id');
            $table->index('currency_id');
            $table->index('uuid');
            $table->index('slug');
            $table->index('short_slug');
            $table->index('is_deleted');
            $table->index('is_default');
            $table->index('users_id');
            $table->index('created_at');
            $table->index('updated_at');
        });

        DB::transaction(function () {
            $regions = DB::table('inventory.regions')->get()->map(function ($region) {
                return (array) $region;
            })->toArray();

            // Insert in chunks
            $chunkSize = 500; // Adjust based on size and available memory
            foreach (array_chunk($regions, $chunkSize) as $chunk) {
                DB::table('regions')->insert($chunk);
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('regions');
    }
};
