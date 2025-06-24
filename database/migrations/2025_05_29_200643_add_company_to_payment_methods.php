<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('payment_methods_credentials', function (Blueprint $table) {
            $table->renameIndex('companies_id', 'companies_groups_id');
            $table->unsignedBigInteger('companies_id')->index('companies_id')->nullable()->after('apps_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('payment_methods_credentials', function (Blueprint $table) {
            $table->dropColumn('companies_id');
            $table->renameIndex('companies_groups_id', 'companies_id');
        });
    }
};
