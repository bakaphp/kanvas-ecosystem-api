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
        Schema::table('languages', function (Blueprint $table) {
            // Drop the original id column (which will automatically remove the primary key)
            $table->dropColumn('id');
        });

        Schema::table('languages', function (Blueprint $table) {
            // Add a new auto-increment big integer id column
            $table->bigIncrements('id')->first();
            $table->string('code', 2)->after('title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};
