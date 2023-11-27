<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
    * Run the migrations.
    */
    public function up(): void
    {
        Schema::table('users_associated_apps', function (Blueprint $table) {
            // Add new columns to the 'users_associated_apps' table
            $table->string('firstname')->after('identify_id')->nullable();
            $table->string('lastname')->after('firstname')->nullable();
            $table->string('email', 191)->after('lastname')->nullable()->index();
            $table->boolean('is_active')->after(('email'))->default(true)->index();
            $table->index(['users_id', 'apps_id', 'companies_id', 'is_deleted'], 'users_associated_deleted_index');
            $table->index(['users_id', 'apps_id', 'companies_id', 'is_active', 'is_deleted'], 'users_associated_active_deleted_index');
        });

        DB::table('users_associated_apps')
        ->join('users', 'users.id', '=', 'users_associated_apps.users_id')
        ->where('users_associated_apps.companies_id', 0)
        ->update([
            'users_associated_apps.firstname' => DB::raw('users.firstname'),
            'users_associated_apps.lastname' => DB::raw('users.lastname'),
            'users_associated_apps.email' => DB::raw('users.email'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users_associated_apps', function (Blueprint $table) {
            // Reverse the operations, drop the added columns
            $table->dropColumn('firstname');
            $table->dropColumn('lastname');
            $table->dropColumn('email');
            $table->dropColumn('is_active');
        });
    }
};
