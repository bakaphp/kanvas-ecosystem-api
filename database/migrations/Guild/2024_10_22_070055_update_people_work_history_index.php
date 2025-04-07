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
        // peoples_employment_history table
        Schema::table('peoples_employment_history', function (Blueprint $table) {
            $table->index(['peoples_id', 'end_date'], 'idx_peoples_employment_history_peoples_id_end_date');
        });

        // organizations_peoples table
        Schema::table('organizations_peoples', function (Blueprint $table) {
            $table->index('peoples_id', 'idx_organizations_peoples_peoples_id');
        });

        // peoples_contacts table
        Schema::table('peoples_contacts', function (Blueprint $table) {
            $table->index(['peoples_id', 'contacts_types_id'], 'idx_peoples_contacts_peoples_id_contacts_types_id');
        });

        // peoples table
        Schema::table('peoples', function (Blueprint $table) {
            $table->index(['apps_id', 'is_deleted'], 'idx_peoples_apps_id_is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop indexes if necessary
        Schema::table('peoples_employment_history', function (Blueprint $table) {
            $table->dropIndex('idx_peoples_employment_history_peoples_id_end_date');
        });

        Schema::table('organizations_peoples', function (Blueprint $table) {
            $table->dropIndex('idx_organizations_peoples_peoples_id');
        });

        Schema::table('peoples_contacts', function (Blueprint $table) {
            $table->dropIndex('idx_peoples_contacts_peoples_id_contacts_types_id');
        });

        Schema::table('peoples', function (Blueprint $table) {
            $table->dropIndex('idx_peoples_apps_id_is_deleted');
        });
    }
};
