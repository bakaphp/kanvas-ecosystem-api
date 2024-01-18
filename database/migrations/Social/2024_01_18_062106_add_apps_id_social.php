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

        Schema::connection('social')->table('users_follows', function (Blueprint $table) {
            $table->string('apps_id')->nullable()->after('id');
        });

        Schema::connection('social')->table('flags', function (Blueprint $table) {
            $table->string('apps_id')->nullable()->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('social')->table('users_follows', function (Blueprint $table) {
            $table->dropColumn('apps_id');
        });
        
        Schema::connection('social')->table('flags', function (Blueprint $table) {
            $table->dropColumn('apps_id');
        });

    }
};
