<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_linked_sources', function (Blueprint $table) {
            $table->unsignedBigInteger('apps_id')->nullable()->after('users_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_link_source', function (Blueprint $table) {
            $table->dropColumn('apps_id');
        });
    }
};
