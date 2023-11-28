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
        Schema::table('peoples', function (Blueprint $table) {
            $table->string('firstname')->after('name')->nullable();
            $table->string('middlename')->after('firstname')->nullable();
            $table->string('lastname')->after('middlename')->nullable();
        });

        DB::statement("
            UPDATE peoples
            SET
                firstname = IF(LOCATE(' ', name) > 0, SUBSTRING_INDEX(name, ' ', 1), name),
                lastname = CASE
                    WHEN LOCATE(' ', name) > 0 THEN SUBSTRING(name, LOCATE(' ', name) + 1)
                    ELSE ''
                END
            WHERE name IS NOT NULL
        ");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('peoples', function (Blueprint $table) {
            $table->dropColumn('firstname');
            $table->dropColumn('middlename');
            $table->dropColumn('lastname');
        });
    }
};
