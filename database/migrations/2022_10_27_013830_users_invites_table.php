<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users_invite', function (Blueprint $table) {
            //
            $table->bigInteger('companies_branches_id')->nullable()->after('companies_id');
            $table->string('firstname')->nullable()->after('email');
            $table->string('lastname')->nullable()->after('firstname');
            $table->string('description')->nullable()->after('lastname');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users_invite', function (Blueprint $table) {
            //
            $table->dropColumn('companies_branches_id');
            $table->dropColumn('firstname');
            $table->dropColumn('lastname');
            $table->dropColumn('description');
        });
    }
};
