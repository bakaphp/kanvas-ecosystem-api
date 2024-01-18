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
        Schema::connection('social')->table('channels', function (Blueprint $table) {
            $table->bigInteger('companies_id')->unsigned()->after('id');
            $table->bigInteger('apps_id')->unsigned()->after('companies_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('social')->table('channels', function (Blueprint $table) {
            $table->dropColumn('companies_id');
            $table->dropColumn('apps_id');
        });
    }
};
