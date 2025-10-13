<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Drop the foreign key first
        Schema::table('participant_passes', function (Blueprint $table) {
            $table->dropForeign(['participant_id']);
        });

        // Make participant_id nullable
        Schema::table('participant_passes', function (Blueprint $table) {
            $table->unsignedBigInteger('participant_id')->nullable()->change();
        });

        // Re-add the foreign key
        Schema::table('participant_passes', function (Blueprint $table) {
            $table->foreign('participant_id')->references('id')->on('participants');
        });
    }

    public function down(): void
    {
        // Drop the foreign key
        Schema::table('participant_passes', function (Blueprint $table) {
            $table->dropForeign(['participant_id']);
        });

        // Make participant_id required again
        Schema::table('participant_passes', function (Blueprint $table) {
            $table->unsignedBigInteger('participant_id')->nullable(false)->change();
        });

        // Re-add the foreign key
        Schema::table('participant_passes', function (Blueprint $table) {
            $table->foreign('participant_id')->references('id')->on('participants');
        });
    }
};
