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
        Schema::table('pipelines', function (Blueprint $table) {
            $table->char('uuid', 36)->nullable()->after('id')->index();
            $table->bigInteger('apps_id')->nullable()->after('uuid')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pipelines', function (Blueprint $table) {
            $table->dropColumn('uuid');
            $table->dropColumn('apps_id');
        });
    }
};
