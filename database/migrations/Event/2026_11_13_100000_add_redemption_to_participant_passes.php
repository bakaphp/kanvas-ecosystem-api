<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('participant_passes', 'redeemed_at')) {
            Schema::table('participant_passes', function (Blueprint $table) {
                $table->dateTime('redeemed_at')->nullable();
            });
        }

        if (! Schema::hasColumn('participant_passes', 'redeemed_event_version_id')) {
            Schema::table('participant_passes', function (Blueprint $table) {
                $table->unsignedBigInteger('redeemed_event_version_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('participant_passes', 'redeemed_event_version_id')) {
            Schema::table('participant_passes', function (Blueprint $table) {
                $table->dropIndex(['redeemed_event_version_id']);
                $table->dropColumn('redeemed_event_version_id');
            });
        }

        if (Schema::hasColumn('participant_passes', 'redeemed_at')) {
            Schema::table('participant_passes', function (Blueprint $table) {
                $table->dropColumn('redeemed_at');
            });
        }
    }
};
