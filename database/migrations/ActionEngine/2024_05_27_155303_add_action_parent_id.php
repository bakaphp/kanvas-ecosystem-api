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
        Schema::table('actions', function (Blueprint $table) {
            // Ensure 'parent_id' and 'id' have the same type
            $table->bigInteger('parent_id')->nullable()->after('users_id')->index();
            $table->string('path')->nullable()->after('parent_id')->index();

            // Add the foreign key constraint
            $table->foreign('parent_id')->references('id')->on('actions')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table) {
            $table->dropColumn('parent_id');
            $table->dropColumn('path');
        });
    }
};
