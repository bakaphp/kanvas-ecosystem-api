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
        Schema::connection('crm')->table('peoples_contacts', function (Blueprint $table) {
            $table->smallInteger('is_opt_out')->default(0)->nullable()->after('value')->index('opt_out');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('crm')->table('peoples_contacts', function (Blueprint $table) {
            $table->dropColumn('is_opt_out');
        });
    }
};
