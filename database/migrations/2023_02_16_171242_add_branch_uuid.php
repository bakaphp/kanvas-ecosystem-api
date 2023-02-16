<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('companies_branches', function (Blueprint $table) {
            $table->char('uuid', 36)
                ->nullable()
                ->after('id')
                ->index('uuid');
        });

        DB::statement('UPDATE companies_branches SET uuid = UUID()');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
};
