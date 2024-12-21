<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {

    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->tinyInteger('total_purchased')->after('total_shared')->default(0)->index('total_purchased');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('total_purchased');
        });
    }
};
