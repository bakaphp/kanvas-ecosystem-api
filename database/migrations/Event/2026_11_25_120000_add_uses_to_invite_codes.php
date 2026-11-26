<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'event';

    public function up(): void
    {
        Schema::connection('event')->table('participant_passes', function (Blueprint $table) {
            $table->boolean('event_wide')->default(false);
        });

        Schema::connection('event')->create('participant_pass_redemptions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('participant_passes_id');
            $table->unsignedBigInteger('booking_event_version_id')->index();
            $table->timestamps();
            $table->unique(['participant_passes_id', 'booking_event_version_id'], 'participant_pass_redemptions_unique');
        });

        DB::connection('event')->statement(
            'INSERT IGNORE INTO participant_pass_redemptions '
            . '(participant_passes_id, booking_event_version_id, created_at, updated_at) '
            . 'SELECT id, redeemed_event_version_id, NOW(), NOW() FROM participant_passes '
            . 'WHERE redeemed_event_version_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::connection('event')->dropIfExists('participant_pass_redemptions');

        Schema::connection('event')->table('participant_passes', function (Blueprint $table) {
            $table->dropColumn('event_wide');
        });
    }
};
