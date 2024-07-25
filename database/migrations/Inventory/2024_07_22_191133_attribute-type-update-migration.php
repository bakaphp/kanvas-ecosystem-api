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
            $table->bigInteger('attributes_type_id')->unsigned()->after('slug')->nullable();
            $table->foreign('attributes_type_id')->references('id')->on('attributes_types_input');
            $table->index('attributes_type_id');
        });

        Schema::table('attributes_types_input', function (Blueprint $table) {
            $table->boolean('is_default')->default(false);
            $table->index(['is_default', 'companies_id', 'apps_id'], 'is_default_companies_id_app');
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
