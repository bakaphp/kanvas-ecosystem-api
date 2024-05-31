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
        Schema::table('peoples', function (Blueprint $table) {
            //
            $table->bigInteger('apps_id')->unsigned()->nullable()->index()->after('companies_id');
        });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('peoples', function (Blueprint $table) {
            //
            $table->dropColumn('apps_id');
        });
    }
};
