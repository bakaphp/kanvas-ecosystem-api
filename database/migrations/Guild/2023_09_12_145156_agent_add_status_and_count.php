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
        Schema::table('agents', function (Blueprint $table) {
            $table->integer('total_leads')->default(0);
            $table->bigInteger('member_id')->change();
            $table->tinyInteger('status_id')->default(1);
            $table->index('status_id');
            $table->index('total_leads');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};
