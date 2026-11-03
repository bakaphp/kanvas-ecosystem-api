<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery tracking stops being Twilio's: one attempt table and one event table for every provider,
 * told apart by `provider`. Existing rows are all Twilio, which is the column default.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('social')->rename('twilio_message_attempts', 'message_attempts');
        Schema::connection('social')->rename('twilio_message_delivery_events', 'message_delivery_events');

        Schema::connection('social')->table('message_attempts', function (Blueprint $table) {
            $table->renameColumn('message_sid', 'provider_message_id');
            $table->renameColumn('account_sid', 'provider_account');
            $table->renameColumn('from_number', 'from_address');
            $table->renameColumn('to_number', 'to_address');
        });

        Schema::connection('social')->table('message_attempts', function (Blueprint $table) {
            $table->string('provider', 32)->default('twilio')->after('lead_id')->index();
            // A Mailgun Message-Id (`<20261103...@mg.example.com>`) and an email address outgrow 64.
            $table->string('provider_message_id', 255)->nullable()->change();
            $table->string('from_address', 255)->nullable()->change();
            $table->string('to_address', 255)->nullable()->change();
        });

        Schema::connection('social')->table('message_delivery_events', function (Blueprint $table) {
            $table->string('provider', 32)->default('twilio')->after('attempt_id')->index();
        });
    }

    public function down(): void
    {
        Schema::connection('social')->table('message_delivery_events', function (Blueprint $table) {
            $table->dropColumn('provider');
        });

        Schema::connection('social')->table('message_attempts', function (Blueprint $table) {
            $table->dropColumn('provider');
            $table->renameColumn('provider_message_id', 'message_sid');
            $table->renameColumn('provider_account', 'account_sid');
            $table->renameColumn('from_address', 'from_number');
            $table->renameColumn('to_address', 'to_number');
        });

        Schema::connection('social')->rename('message_delivery_events', 'twilio_message_delivery_events');
        Schema::connection('social')->rename('message_attempts', 'twilio_message_attempts');
    }
};
