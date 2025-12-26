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
        Schema::table('pipelines_stages', function (Blueprint $table) {
            $table->decimal('weight', 8, 2)->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pipelines_stages', function (Blueprint $table) {
            $table->decimal('weight')->default(0)->change();
        });
    }
};
