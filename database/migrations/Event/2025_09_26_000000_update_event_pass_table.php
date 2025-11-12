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
        Schema::table('participant_passes', function (Blueprint $table) {
            $table->string('scope', 16)->nullable()->after('users_id');   // RESERVATION|PARTICIPANT
            $table->string('format', 8)->nullable()->after('scope');    // QR|PIN

            $table->string('qr_id', 32)->nullable()->after('format');
            $table->text('payload')->nullable()->after('qr_id');
            $table->string('signature', 128)->nullable()->after('payload');

            $table->string('pin_lookup', 32)->nullable()->after('signature');
            $table->string('pin_hash', 255)->nullable()->after('pin_lookup');
            $table->index('pin_lookup');

            $table->boolean('one_time')->default(false)->after('pin_hash');
            $table->unsignedInteger('use_count')->default(0)->after('one_time');
            $table->unsignedInteger('max_uses')->nullable()->after('use_count');
            $table->date('used_date')->nullable()->change();

            $table->dateTime('revoked_at')->nullable()->after('expiration_date');

            $table->index(['apps_id','companies_id','event_id','participant_id'], 'pp_app_comp_event_participant_idx');
            $table->index(['event_id','participant_id','scope','format'], 'pp_event_participant_scope_fmt_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_resources');
    }
};
